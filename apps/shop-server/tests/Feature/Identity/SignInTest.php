<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Role;
use App\Identity\Users;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * Name tiles, PINs and the acting-user token (spec section 3; checks in section 14 D) [F8, F9, S6].
 */

beforeEach(function () {
    $this->shop = Shop::create();
    [$this->t1, $this->t2] = $this->shop['terminals'];
    $this->amina = People::add('Amina', Role::Cashier, '1357');
    $this->tunde = People::add('Tunde', Role::Cashier, '2468');
    $this->device1 = People::enrol($this->t1);
    $this->device2 = People::enrol($this->t2);
});

function signIn(string $device, string $userId, string $pin): TestResponse
{
    return test()->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $userId, 'pin' => $pin]);
}

it('sends a PC that is not enrolled to the enrolment page', function () {
    $this->get('/sign-in')->assertRedirect('/enrol');
    $this->postJson('/api/identity/pin', ['user_id' => $this->amina->id, 'pin' => '1357'])->assertUnauthorized();
});

it('shows the name tiles to an enrolled counter, with no PIN hash anywhere', function () {
    $this->withCookie(Enrolment::COOKIE, $this->device1)->get('/sign-in')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('identity/sign-in')
            ->has('tiles', 2)
            ->where('tiles.0.name', 'Amina')
            ->where('tiles.0.pinLength', [4, 6])
            ->where('terminal.code', 'T01')
            ->where('idleLockMinutes', 3))
        ->assertDontSee('argon2id');
});

it('signs in with the right PIN and mints a token bound to this counter', function () {
    $response = signIn($this->device1, $this->amina->id, '1357')->assertOk()->assertJsonPath('user.name', 'Amina');

    $token = ActingToken::verify((string) $response->json('token'));
    expect($token?->userId())->toBe($this->amina->id);
    expect($token?->terminalId())->toBe($this->t1->id);
    expect(DB::table('audit_log')->where('action', 'identity.signed_in')->where('terminal_id', $this->t1->id)->count())->toBe(1);
});

it('refuses a wrong PIN in plain words', function () {
    signIn($this->device1, $this->amina->id, '0000')
        ->assertStatus(422)
        ->assertJsonPath('message', 'That PIN is not right. Try again.');
});

it('locks a person after five wrong PINs, even with the right one', function () {
    foreach (range(1, 4) as $i) {
        signIn($this->device1, $this->amina->id, '0000')->assertStatus(422);
    }
    signIn($this->device1, $this->amina->id, '0000')->assertStatus(423)->assertJsonPath('outcome', 'locked');
    signIn($this->device1, $this->amina->id, '1357')->assertStatus(423);

    // Other people on the same counter are not locked.
    signIn($this->device1, $this->tunde->id, '2468')->assertOk();

    // After the cooldown the right PIN works again.
    $this->travel(16)->minutes();
    signIn($this->device1, $this->amina->id, '1357')->assertOk();
});

it('backs a counter off after ten wrong PINs across names in ten minutes', function () {
    $people = collect(range(1, 5))->map(fn ($i) => People::add("Person {$i}", Role::Cashier, '9999'));
    foreach ($people as $person) {
        signIn($this->device1, $person->id, '0000');
        signIn($this->device1, $person->id, '0001');
    }

    signIn($this->device1, $this->amina->id, '1357')->assertStatus(429)->assertJsonPath('outcome', 'backoff');
    // Another counter is not slowed.
    signIn($this->device2, $this->amina->id, '1357')->assertOk();
});

it('accepts a token only from the counter that minted it', function () {
    $token = (string) signIn($this->device1, $this->amina->id, '1357')->json('token');

    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $token)->getJson('/api/identity/me')->assertOk();
    $this->withCookie(Enrolment::COOKIE, $this->device2)->withHeader(ActingToken::HEADER, $token)->getJson('/api/identity/me')->assertUnauthorized();
});

it('rejects a tampered token', function () {
    $token = (string) signIn($this->device1, $this->amina->id, '1357')->json('token');
    [$payload, $signature] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'u' => $this->tunde->id, 't' => $this->t1->id, 'r' => 'owner', 'iat' => time(), 'exp' => time() + 600])), '+/', '-_'), '=');

    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $forged.'.'.$signature)->getJson('/api/identity/me')->assertUnauthorized();
});

it('locks an idle cashier after the idle limit, and renews the token while active', function () {
    $token = (string) signIn($this->device1, $this->amina->id, '1357')->json('token');

    $this->travel(2)->minutes();
    $renewed = $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $token)
        ->getJson('/api/identity/me')->assertOk()->headers->get(ActingToken::HEADER);

    // Two more minutes: the first token has expired, the renewed one has not.
    $this->travel(2)->minutes();
    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $token)->getJson('/api/identity/me')->assertUnauthorized();
    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, (string) $renewed)->getJson('/api/identity/me')->assertOk();
});

it('expires a manager sooner than a cashier: an elevated session', function () {
    $manager = People::add('Bola', Role::Manager, '135790');
    $token = (string) signIn($this->device1, $manager->id, '135790')->assertOk()->json('token');

    $this->travel(150)->seconds();
    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $token)->getJson('/api/identity/me')->assertUnauthorized();
});

it('attributes each request to the person who sent it, even across a PIN switch', function () {
    $aminaToken = (string) signIn($this->device1, $this->amina->id, '1357')->json('token');
    $tundeToken = (string) signIn($this->device1, $this->tunde->id, '2468')->json('token');

    // Amina's request was sent before the switch and arrives after it: it is still hers.
    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $aminaToken)->getJson('/api/identity/me')->assertJsonPath('user.name', 'Amina');
    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $tundeToken)->getJson('/api/identity/me')->assertJsonPath('user.name', 'Tunde');
});

it('stops a deactivated person at once, even with a live token', function () {
    $token = (string) signIn($this->device1, $this->amina->id, '1357')->json('token');
    app(Users::class)->deactivate($this->amina);

    $this->withCookie(Enrolment::COOKIE, $this->device1)->withHeader(ActingToken::HEADER, $token)->getJson('/api/identity/me')->assertUnauthorized();
    signIn($this->device1, $this->amina->id, '1357')->assertStatus(422);
});

it('signs in well inside two seconds, with the PIN hashed at a shop\'s full Argon2id cost (check D3)', function () {
    // phpunit.xml makes Argon2id cheap so the suite stays quick; a shop hashes PINs at the cost in
    // config/hashing.php (64 MiB, 4 passes, 1 thread), which a check reads back from the hash itself.
    $full = password_hash('1357', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
    DB::table('users')->where('id', $this->amina->id)->update(['pin_hash' => $full]);
    expect(password_get_info($full)['options'])->toMatchArray(['memory_cost' => 65536, 'time_cost' => 4]);

    $started = microtime(true);
    signIn($this->device1, $this->amina->id, '1357')->assertOk();
    expect(microtime(true) - $started)->toBeLessThan(2.0);
});

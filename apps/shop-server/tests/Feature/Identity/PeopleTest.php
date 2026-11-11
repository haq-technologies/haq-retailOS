<?php

declare(strict_types=1);

use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Pins;
use App\Identity\Role;
use App\Identity\Throttle;
use App\Identity\Users;
use Illuminate\Support\Facades\DB;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * People and counters (spec section 3) [F8, F18].
 */

beforeEach(fn () => $this->shop = Shop::create());

it('gives cashiers 4 to 6 digits and managers and owners exactly 6 (D24)', function () {
    $users = app(Users::class);
    expect($users->add('Amina', Role::Cashier, '1357')->role)->toBe(Role::Cashier);
    expect(fn () => $users->add('Too short', Role::Cashier, '135'))->toThrow(InvalidArgumentException::class, "A cashier's PIN is 4 to 6 digits.");
    expect(fn () => $users->add('Bola', Role::Manager, '1357'))->toThrow(InvalidArgumentException::class, "A manager's PIN is 6 digits.");
    expect(fn () => $users->add('Letters', Role::Cashier, '12ab'))->toThrow(InvalidArgumentException::class);
});

it('lets two people share a PIN, because the tile says who is who', function () {
    $a = People::add('Amina', Role::Cashier, '1357');
    $b = People::add('Tunde', Role::Cashier, '1357');
    expect($a->pin_hash)->not->toBe($b->pin_hash);
});

it('never deactivates the last active owner', function () {
    $owner = People::add('Owner', Role::Owner);
    expect(fn () => app(Users::class)->deactivate($owner))->toThrow(RuntimeException::class, 'last active owner');

    $second = People::add('Second owner', Role::Owner);
    app(Users::class)->deactivate($owner);
    expect($owner->fresh()?->isActive())->toBeFalse();
    expect(fn () => app(Users::class)->deactivate($second))->toThrow(RuntimeException::class, 'last active owner');
});

it('adds a person from the command line', function () {
    $this->artisan('shop:add-user', ['name' => 'Chika', '--role' => 'storekeeper', '--pin' => '8642'])->assertSuccessful();
    expect(User::query()->where('name', 'Chika')->firstOrFail()->role)->toBe(Role::Storekeeper);
});

it('enrols a counter with a code that works once', function () {
    [$t1] = $this->shop['terminals'];
    $enrolment = app(Enrolment::class);
    $code = $enrolment->issueCode($t1);

    $this->post('/enrol', ['code' => $code])->assertRedirect('/')->assertCookie(Enrolment::COOKIE);
    $this->post('/enrol', ['code' => $code])->assertSessionHasErrors('code');
});

it('keeps a code working for 30 minutes, long enough to set up the counter, and not after (D58)', function () {
    [$t1, $t2] = $this->shop['terminals'];
    $enrolment = app(Enrolment::class);
    $this->freezeTime();
    $early = $enrolment->issueCode($t1);
    $late = $enrolment->issueCode($t2);

    $this->travel(29)->minutes();
    $this->post('/enrol', ['code' => $early])->assertRedirect('/')->assertCookie(Enrolment::COOKIE);
    $this->travel(2)->minutes();
    $this->post('/enrol', ['code' => $late])->assertSessionHasErrors('code');
});

it('stops the old PC when a counter is enrolled again', function () {
    [$t1] = $this->shop['terminals'];
    $old = People::enrol($t1);
    $new = People::enrol($t1);

    $this->withCookie(Enrolment::COOKIE, $old)->get('/sign-in')->assertRedirect('/enrol');
    $this->withCookie(Enrolment::COOKIE, $new)->get('/sign-in')->assertOk();
});

it('prints a one-time code for the installer', function () {
    $this->artisan('shop:enrol-terminal', ['code' => 'T01'])->expectsOutputToContain('open /enrol in the browser')->expectsOutputToContain('for 30 minutes')->assertSuccessful();
    $this->artisan('shop:enrol-terminal', ['code' => 'T09'])->assertFailed();
});

it('lists everyone for HAQ support, the active first, with their role, status and ID (D74, QA finding P15)', function () {
    $amina = People::add('Amina', Role::Cashier, '1357');
    $kemi = People::add('Kemi', Role::Manager, '246810');
    $tunde = People::add('Tunde', Role::Cashier, '2468');
    app(Users::class)->deactivate($tunde);

    // One check per table row (a row meets one check): Amina's ID, Kemi's role, and Tunde, last, deactivated.
    $this->artisan('shop:list-users')
        ->expectsOutputToContain($amina->id)->expectsOutputToContain('Manager')->expectsOutputToContain('Deactivated')->assertSuccessful();
    expect($kemi->pin_changed_at)->not->toBeNull();
});

it('gives a person a new PIN by the name on their tile, and the old one stops working (D74, QA finding P15)', function () {
    $amina = People::add('Amina', Role::Cashier, '1357');
    $kemi = People::add('Kemi', Role::Manager, '246810');
    $pins = app(Pins::class);

    $this->artisan('shop:set-pin', ['person' => ' amina ', '--pin' => '9753'])->expectsOutputToContain("Amina's PIN is changed")->assertSuccessful();
    expect($pins->check('9753', $amina->fresh()?->pin_hash))->toBeTrue()
        ->and($pins->check('1357', $amina->fresh()?->pin_hash))->toBeFalse();
    expect(DB::table('audit_log')->where('action', 'identity.pin_changed')->where('subject_id', $amina->id)->exists())->toBeTrue();

    // A manager's PIN is 6 digits, because it also approves; a wrong length changes nothing.
    $this->artisan('shop:set-pin', ['person' => 'Kemi', '--pin' => '1234'])->expectsOutputToContain("A manager's PIN is 6 digits.")->assertFailed();
    expect($pins->check('246810', $kemi->fresh()?->pin_hash))->toBeTrue();

    // Someone locked after wrong PINs is told when the new PIN starts to work.
    foreach (range(1, 5) as $_) {
        app(Throttle::class)->record($this->shop['terminals'][0]->id, $kemi->id, 'sign_in', false);
    }
    $this->artisan('shop:set-pin', ['person' => $kemi->id, '--pin' => '864200'])->expectsOutputToContain('still locked after wrong PINs')->assertSuccessful();
});

it('deactivates someone who leaves by name, and asks for the ID when two share the name (D74, QA finding P15)', function () {
    $first = People::add('Musa', Role::Cashier, '1357');
    $second = People::add('Musa', Role::Cashier, '2468');
    People::add('Halima', Role::Owner);

    $this->artisan('shop:deactivate-user', ['person' => 'Musa'])
        ->expectsOutputToContain('2 active people are called Musa')->expectsOutputToContain($first->id)->expectsOutputToContain($second->id)->assertFailed();
    expect($first->fresh()?->isActive())->toBeTrue()->and($second->fresh()?->isActive())->toBeTrue();

    $this->artisan('shop:deactivate-user', ['person' => strtoupper($first->id)])->expectsOutputToContain('Musa (Cashier) is deactivated')->assertSuccessful();
    expect($first->fresh()?->isActive())->toBeFalse()->and($second->fresh()?->isActive())->toBeTrue();
    $this->artisan('shop:deactivate-user', ['person' => $first->id])->expectsOutputToContain('was already deactivated')->assertSuccessful();
    $this->artisan('shop:set-pin', ['person' => $first->id, '--pin' => '9999'])->expectsOutputToContain('is deactivated')->assertFailed();

    // Now only one active Musa is left, so the name is enough.
    $this->artisan('shop:deactivate-user', ['person' => 'musa'])->assertSuccessful();
    expect($second->fresh()?->isActive())->toBeFalse();

    $this->artisan('shop:deactivate-user', ['person' => 'Nobody'])->expectsOutputToContain('There is no active person called Nobody')->assertFailed();
    $this->artisan('shop:deactivate-user', ['person' => 'Halima'])->expectsOutputToContain('last active owner')->assertFailed();
});

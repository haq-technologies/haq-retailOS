<?php

declare(strict_types=1);

use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Identity\Users;
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

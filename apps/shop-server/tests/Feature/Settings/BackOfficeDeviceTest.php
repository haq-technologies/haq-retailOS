<?php

declare(strict_types=1);

use App\Identity\Enrolment;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Settings\Profile\InvalidProfile;
use App\Settings\Profile\Profile;
use App\Settings\Registry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\People;

/*
 * Back-office devices (D29): the owner's phone or laptop on the shop's network is enrolled like a counter,
 * signs in the same way and opens the back office, but never sells.
 */

beforeEach(function () {
    $this->artisan('shop:apply-profile', ['path' => base_path('docker/profile.example.json')])->assertSuccessful();
    $this->device = Terminal::query()->where('code', 'D01')->firstOrFail();
});

it('adds a back-office device that cannot sell and has no drawer', function () {
    expect($this->device->kind)->toBe('back_office');
    expect($this->device->canSell())->toBeFalse();
    expect(Terminal::query()->where('code', 'T01')->firstOrFail()->canSell())->toBeTrue();
    expect(DB::table('drawers')->where('terminal_id', $this->device->id)->exists())->toBeFalse();
});

it('enrols and signs in like a counter, and says it cannot sell', function () {
    $owner = People::add('Owner', Role::Owner, '246810');
    $device = People::enrol($this->device);

    $this->withCookie(Enrolment::COOKIE, $device)->get('/sign-in')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('terminal.code', 'D01')->where('terminal.canSell', false));
    $this->withCookie(Enrolment::COOKIE, $device)->postJson('/api/identity/pin', ['user_id' => $owner->id, 'pin' => '246810'])->assertOk();
});

it('refuses a back-office device with a drawer, or with a counter\'s code', function () {
    $profile = json_decode((string) file_get_contents(base_path('docker/profile.example.json')), true);
    $profile['terminals'][] = ['code' => 'T05', 'name' => 'Laptop', 'drawer' => true, 'kind' => 'back_office'];

    try {
        Profile::fromArray($profile, app(Registry::class));
        $this->fail('The profile should not be valid.');
    } catch (InvalidProfile $e) {
        expect($e->errors)->toContain('T05 is a back-office device, so its code starts with D, such as D01.');
        expect($e->errors)->toContain('T05 is a back-office device, so it has no cash drawer.');
    }
});

it('never changes a terminal\'s code or kind once written', function () {
    expect(fn () => DB::transaction(fn () => DB::table('terminals')->where('code', 'D01')->update(['kind' => 'counter'])))
        ->toThrow(QueryException::class, 'terminals.kind never changes');
    expect(fn () => DB::transaction(fn () => DB::table('terminals')->where('code', 'T01')->update(['code' => 'T09'])))
        ->toThrow(QueryException::class, 'terminals.code never changes');
});

it('makes back-office devices in a new profile', function () {
    $path = storage_path('framework/testing/back-office-'.getmypid().'.json');
    $this->artisan('shop:make-profile', ['--shop' => 'X', '--terminals' => 1, '--back-office' => 2, '--output' => $path, '--no-interaction' => true])->assertSuccessful();
    $terminals = json_decode((string) file_get_contents($path), true)['terminals'];
    @unlink($path);

    expect(array_column($terminals, 'kind', 'code'))->toBe(['T01' => 'counter', 'D01' => 'back_office', 'D02' => 'back_office']);
});

<?php

declare(strict_types=1);

use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Sales\Parked;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Support\People;
use Tests\Support\Shop;

/*
 * The owner's Shop settings (D37): the owner sets the closing time; nobody else does, and a setting HAQ
 * has locked stays as HAQ set it.
 */

beforeEach(function () {
    $shop = Shop::create();
    $this->device = People::enrol($shop['terminals'][0]);
    People::add('Halima', Role::Owner, '135790');
    People::add('Amina', Role::Cashier, '1357');
    $this->as = function (string $name, string $pin) {
        $user = User::query()->where('name', $name)->firstOrFail();
        $token = (string) $this->withCookie(Enrolment::COOKIE, $this->device)->postJson('/api/identity/pin', ['user_id' => $user->id, 'pin' => $pin])->json('token');

        return $this->withCookie(Enrolment::COOKIE, $this->device)->withHeader(ActingToken::HEADER, $token);
    };
});

it('lets the owner set the closing time, keeps it in the history, and the forgotten-shift rule uses it', function () {
    $owner = ($this->as)('Halima', '135790');
    $owner->getJson('/api/back-office/settings')->assertOk()
        ->assertJsonPath('settings.0.key', 'shifts.closing_time')->assertJsonPath('settings.0.value', null)->assertJsonPath('settings.0.locked', false);

    $owner->postJson('/api/back-office/settings', ['key' => 'shifts.closing_time', 'value' => '21:30'])->assertOk()->assertJsonPath('value', '21:30');
    expect(app(Settings::class)->get('shifts.closing_time'))->toBe('21:30');
    expect(DB::table('settings_history')->where('key', 'shifts.closing_time')->value('source'))->toBe('owner');

    $owner->postJson('/api/back-office/settings', ['key' => 'shifts.closing_time', 'value' => '9:30pm'])->assertStatus(422);
    $owner->postJson('/api/back-office/settings', ['key' => 'shifts.closing_time', 'value' => ''])->assertOk()->assertJsonPath('value', null);
});

it('lets nobody but the owner change it, and never a setting not meant for the owner', function () {
    ($this->as)('Amina', '1357')->postJson('/api/back-office/settings', ['key' => 'shifts.closing_time', 'value' => '21:30'])->assertForbidden();
    ($this->as)('Halima', '135790')->postJson('/api/back-office/settings', ['key' => 'pricing.floor_percent', 'value' => 10])->assertNotFound();
    expect(app(Settings::class)->get('pricing.floor_percent'))->toBe(50);
});

it('shows a setting HAQ locked, and refuses to change it', function () {
    app(Settings::class)->set('shifts.closing_time', '22:00', 'console', locked: true);
    $owner = ($this->as)('Halima', '135790');
    $owner->getJson('/api/back-office/settings')->assertJsonPath('settings.0.locked', true)->assertJsonPath('settings.0.value', '22:00');
    $owner->postJson('/api/back-office/settings', ['key' => 'shifts.closing_time', 'value' => '21:00'])->assertStatus(423);
});

it('lets the owner set a cashier\'s discard limit in naira, kept in kobo, and the discard rule uses it (D38)', function () {
    $owner = ($this->as)('Halima', '135790');
    $owner->getJson('/api/back-office/settings')->assertJsonPath('settings.1.key', 'parked.discard_limit_kobo')
        ->assertJsonPath('settings.1.kind', 'money')->assertJsonPath('settings.1.value', 0);

    $owner->postJson('/api/back-office/settings', ['key' => 'parked.discard_limit_kobo', 'value' => 500_000])->assertOk()->assertJsonPath('value', 500_000);
    expect(app(Parked::class)->limitKobo())->toBe(500_000);
    $owner->postJson('/api/back-office/settings', ['key' => 'parked.discard_limit_kobo', 'value' => ''])->assertStatus(422)
        ->assertJsonPath('message', 'That value is not right for this setting. Type an amount in naira, such as 5000.');
});

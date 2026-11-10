<?php

declare(strict_types=1);

use App\Catalogue\Items;
use App\Catalogue\Models\Category;
use App\Identity\ActingToken;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Reports\CashierDay;
use App\Sales\Parked;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Catalogue;
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

/** One of the owner's settings as Shop settings lists them, found by its key (they are listed by name). */
function ownerSetting(TestResponse $response, string $key): array
{
    return collect($response->assertOk()->json('settings'))->firstWhere('key', $key) ?? [];
}

it('lets the owner set the closing time, keeps it in the history, and the forgotten-shift rule uses it', function () {
    $owner = ($this->as)('Halima', '135790');
    expect(ownerSetting($owner->getJson('/api/back-office/settings'), 'shifts.closing_time'))->toMatchArray(['value' => null, 'locked' => false]);

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
    expect(ownerSetting($owner->getJson('/api/back-office/settings'), 'shifts.closing_time'))->toMatchArray(['locked' => true, 'value' => '22:00']);
    $owner->postJson('/api/back-office/settings', ['key' => 'shifts.closing_time', 'value' => '21:00'])->assertStatus(423);
});

it('lets the owner set a cashier\'s discard limit in naira, kept in kobo, and the discard rule uses it (D38)', function () {
    $owner = ($this->as)('Halima', '135790');
    expect(ownerSetting($owner->getJson('/api/back-office/settings'), 'parked.discard_limit_kobo'))->toMatchArray(['kind' => 'money', 'value' => 0]);

    $owner->postJson('/api/back-office/settings', ['key' => 'parked.discard_limit_kobo', 'value' => 500_000])->assertOk()->assertJsonPath('value', 500_000);
    expect(app(Parked::class)->limitKobo())->toBe(500_000);
    $owner->postJson('/api/back-office/settings', ['key' => 'parked.discard_limit_kobo', 'value' => ''])->assertStatus(422)
        ->assertJsonPath('message', 'That value is not right for this setting. Type an amount in naira, such as 5000.');
});

it('marks a cashier\'s item creation high at the owner\'s number, 10 a day until the owner changes it (D52)', function () {
    $owner = ($this->as)('Halima', '135790');
    $amina = User::query()->where('name', 'Amina')->firstOrFail();
    Catalogue::anyItem('seed the category');
    $category = Category::query()->where('code', 'test_things')->firstOrFail();
    foreach (range(1, 4) as $n) {
        app(Items::class)->findOrCreate($category, freeText: "made at the counter {$n}", source: 'cashier', createdBy: $amina->id);
    }
    $row = fn () => collect(app(CashierDay::class)->forDay(now('Africa/Lagos')->toDateString()))->firstWhere('name', 'Amina');
    expect($row())->items_created->toBe(4)->items_created_high->toBeFalse();

    // The owner finds it in Shop settings at 10, and lowers it.
    $setting = collect($owner->getJson('/api/back-office/settings')->assertOk()->json('settings'))->firstWhere('key', 'flags.items_created_high');
    expect($setting)->toMatchArray(['value' => 10, 'kind' => 'number', 'locked' => false]);
    $owner->postJson('/api/back-office/settings', ['key' => 'flags.items_created_high', 'value' => 4])->assertOk()->assertJsonPath('value', 4);
    expect($row())->items_created_high->toBeTrue();
    $owner->postJson('/api/back-office/settings', ['key' => 'flags.items_created_high', 'value' => 'many'])->assertStatus(422)
        ->assertJsonPath('message', 'That value is not right for this setting. Type a whole number, such as 10.');
    ($this->as)('Amina', '1357')->postJson('/api/back-office/settings', ['key' => 'flags.items_created_high', 'value' => 50])->assertForbidden();
});

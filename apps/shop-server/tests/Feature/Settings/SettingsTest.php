<?php

declare(strict_types=1);

use App\Settings\InvalidSettingValue;
use App\Settings\SettingLocked;
use App\Settings\Settings;
use App\Settings\UnknownSetting;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

/*
 * Settings (spec section 11): resolution, history, audit and the sysadmin lock (D14).
 */

beforeEach(function () {
    $this->shop = Shop::create();
    $this->settings = app(Settings::class);
});

it('falls back to the default, then the shop value, then the branch value', function () {
    expect($this->settings->get('business.timezone'))->toBe('Africa/Lagos');

    $this->settings->set('receipt.address', '1 Shop Street', 'profile');
    expect($this->settings->get('receipt.address'))->toBe('1 Shop Street');

    $this->settings->set('receipt.address', '2 Branch Road', 'profile', scope: 'branch', scopeId: $this->shop['branch']->id);
    expect($this->settings->get('receipt.address'))->toBe('2 Branch Road');
});

it('writes a history row and an audit entry for every change, and nothing for no change', function () {
    expect($this->settings->set('receipt.footer', 'No refunds after 7 days.', 'profile'))->toBeTrue();
    expect($this->settings->set('receipt.footer', 'No refunds after 7 days.', 'profile'))->toBeFalse();
    expect($this->settings->set('receipt.footer', 'Exchanges within 7 days.', 'profile'))->toBeTrue();

    $history = DB::table('settings_history')->where('key', 'receipt.footer')->orderBy('changed_at')->get();
    expect($history)->toHaveCount(2);
    expect(json_decode($history[1]->old_value))->toBe('No refunds after 7 days.');
    expect($history[1]->source)->toBe('profile');

    $audit = DB::table('audit_log')->where('action', 'setting.changed')->count();
    expect($audit)->toBe(2);
});

it('lets only the sysadmin change the receipt text (D14)', function () {
    $this->settings->set('receipt.shop_name', 'Example Accessories', 'profile');
    expect($this->settings->isLocked('receipt.shop_name'))->toBeTrue();

    expect(fn () => $this->settings->set('receipt.shop_name', 'Something Else', 'owner'))->toThrow(SettingLocked::class);
    expect($this->settings->get('receipt.shop_name'))->toBe('Example Accessories');

    expect($this->settings->set('receipt.shop_name', 'Example Accessories Ltd', 'console'))->toBeTrue();
});

it('refuses an owner who tries to lock or unlock a setting', function () {
    $this->settings->set('receipt.phone', '0800 000 0000', 'owner', locked: false);
})->throws(SettingLocked::class);

it('refuses a value its definition does not allow', function () {
    $this->settings->set('business.timezone', 'Lagos time', 'profile');
})->throws(InvalidSettingValue::class);

it('refuses a scope its definition does not allow', function () {
    $this->settings->set('receipt.shop_name', 'Branch name', 'profile', scope: 'branch', scopeId: $this->shop['branch']->id);
})->throws(InvalidSettingValue::class, 'It cannot be set for a branch');

it('refuses a key that is not a setting', function () {
    $this->settings->get('no.such.setting');
})->throws(UnknownSetting::class);

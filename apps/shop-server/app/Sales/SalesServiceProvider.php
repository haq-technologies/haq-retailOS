<?php

declare(strict_types=1);

namespace App\Sales;

use App\Settings\Definition;
use App\Settings\Registry;
use Illuminate\Support\ServiceProvider;

/**
 * The Sales module: Finish, receipt numbers and epochs, set prices and the price checks (spec sections 4
 * and 7) (D23).
 */
class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            // A transfer sale needs the tick that the bank alert was seen (spec section 7) [F12]. On by default.
            $registry->define(new Definition('payments.transfer_alert_tick', 'Transfer needs the alert-seen tick', true, ['required', 'boolean'], lockedByDefault: true, source: 'F12'));
            // Prices (spec section 7) [S4, S5, F11]: console policies, with the spec's defaults.
            $registry->define(new Definition('pricing.fill_fallback', 'Price fill when there is no set price', 'last_price', ['required', 'in:last_price,median'], lockedByDefault: true, source: 'S4'));
            $registry->define(new Definition('pricing.floor_percent', 'Price floor (% of the bottom of the usual range)', 50, ['required', 'integer', 'min:1', 'max:100'], lockedByDefault: true, source: 'F11'));
            $registry->define(new Definition('pricing.below_range_confirms', 'Below-range confirms per shift before a manager is needed', 3, ['required', 'integer', 'min:0', 'max:50'], lockedByDefault: true, source: 'S5'));
            $registry->define(new Definition('pricing.retype_multiple', 'Retype a price above this many times the usual', 3, ['required', 'integer', 'min:2', 'max:20'], lockedByDefault: true, source: 'S5'));

            // A cashier discards their own parked sale up to this amount; above it, a manager approves [F12].
            // The owner sets it in Shop settings (D38); until then it is ₦0, so every cashier discard needs a manager (D33).
            $registry->define(new Definition('parked.discard_limit_kobo', 'Discard limit for a cashier', 0, ['required', 'integer', 'min:0', 'max:100000000'],
                lockedByDefault: false, source: 'F12, D33, D38', ownerFacing: true, ownerKind: 'money',
                help: 'A cashier may discard their own held sale up to this amount, with a reason. Above it, or for someone else\'s sale, a manager or you must type a PIN. At ₦0 every discard needs one.'));

            // How long after a sale it may be returned; 0 means no limit (spec section 9) [F7, D68]. The owner sets it.
            $registry->define(new Definition('returns.window_days', 'Days a sale can be returned', 0, ['required', 'integer', 'min:0', 'max:3650'],
                lockedByDefault: false, source: 'F7, D68', ownerFacing: true, ownerKind: 'number',
                help: 'A sale can be returned for this many days after it was made, with a manager\'s PIN. 0 means no limit.'));

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

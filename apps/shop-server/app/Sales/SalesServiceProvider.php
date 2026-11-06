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

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

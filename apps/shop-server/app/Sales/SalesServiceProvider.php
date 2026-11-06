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

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

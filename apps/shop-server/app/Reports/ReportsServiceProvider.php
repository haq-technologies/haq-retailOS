<?php

declare(strict_types=1);

namespace App\Reports;

use App\Settings\Definition;
use App\Settings\Registry;
use Illuminate\Support\ServiceProvider;

/**
 * The Reports module: the dashboard, profit in three buckets, the Needs-cost queue, flags, the daily
 * summary and catalogue health, and the CSV exports (spec sections 8 and 12) (D23). It reads the records
 * of the modules beneath it and writes only the marks of flags seen.
 */
class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            // A console feature flag (spec section 11): on by default; HAQ can turn below-cost alerts off.
            $registry->define(new Definition('flags.below_cost', 'Below-cost alerts', true, ['required', 'boolean'], lockedByDefault: true, source: 'spec section 11'));
            // A cashier creating this many items in a day is marked "high" on the dashboard (spec section 6)
            // [F11, D52]. The owner changes it in Shop settings; HAQ can lock it.
            $registry->define(new Definition('flags.items_created_high', 'New items a day that count as high', 10, ['required', 'integer', 'min:1', 'max:500'],
                lockedByDefault: false, source: 'F11, D52', ownerFacing: true, ownerKind: 'number',
                help: 'When a cashier creates this many new items at the counter in one day, the dashboard marks it as high, so you can check they are not making duplicates instead of searching.'));

            return $registry;
        });
    }
}

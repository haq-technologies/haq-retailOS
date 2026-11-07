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

            return $registry;
        });
    }
}

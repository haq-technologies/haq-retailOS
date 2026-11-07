<?php

declare(strict_types=1);

namespace App\Cost;

use App\Settings\Definition;
use App\Settings\Registry;
use Illuminate\Support\ServiceProvider;

/**
 * The Cost module: cost entries, who sees cost, and the backfill of earlier sales (spec section 8) (D23).
 */
class CostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            // Console feature flags (spec section 11) [FU6]: off by default, so only the owner sees cost.
            $registry->define(new Definition('cost.manager_sees_cost', 'Managers see cost', false, ['required', 'boolean'], lockedByDefault: true, source: 'spec section 11'));
            $registry->define(new Definition('cost.manager_enters_cost', 'Managers may enter cost (and so see it)', false, ['required', 'boolean'], lockedByDefault: true, source: 'FU6'));

            return $registry;
        });
    }
}

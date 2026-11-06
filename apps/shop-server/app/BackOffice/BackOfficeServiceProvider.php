<?php

declare(strict_types=1);

namespace App\BackOffice;

use Illuminate\Support\ServiceProvider;

/**
 * The BackOffice module: what the owner and the manager do away from the counter (spec section 12) (D23).
 * It starts with the owner's Shop settings (D37); the reports and the review queues come with the MVP.
 */
class BackOfficeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

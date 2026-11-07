<?php

declare(strict_types=1);

namespace App\Health;

use App\Health\Console\CheckCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Health module: the server's health page, its warnings on the owner's dashboard, the server clock's
 * check and catch-up scheduling (spec section 4) [F15, F24, D45].
 */
class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
    }
}

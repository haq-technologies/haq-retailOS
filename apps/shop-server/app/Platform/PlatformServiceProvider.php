<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Console\MutabilityCommand;
use App\Platform\Database\Mutability\Applier;
use App\Platform\Database\Mutability\Matrix;
use App\Platform\Database\Mutability\Roles;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The Platform module: the shared building blocks every other module stands on (D23).
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Matrix::class, fn () => Matrix::shopServer());
        $this->app->singleton(Roles::class, fn () => Roles::fromConfig());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MutabilityCommand::class]);
        }

        // Every migration run ends by applying the matrix on the same connection, so a new table never
        // goes unprotected. A table missing from the matrix stops here.
        Event::listen(MigrationsEnded::class, function (): void {
            $this->app->make(Applier::class)->apply(DB::connection());
        });
    }
}

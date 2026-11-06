<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Console\IngestSpoolCommand;
use App\Audit\Console\RootCommand;
use App\Audit\Console\VerifyCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Audit module: hash chains, roots, the failure spool and verification (spec section 10) (D23).
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Spool::class, fn () => new Spool(config()->string('audit.spool')));
        $this->app->singleton(AuditLog::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RootCommand::class, IngestSpoolCommand::class, VerifyCommand::class]);
        }
    }
}

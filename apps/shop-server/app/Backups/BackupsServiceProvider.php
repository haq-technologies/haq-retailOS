<?php

declare(strict_types=1);

namespace App\Backups;

use App\Backups\Console\AfterRestoreCommand;
use App\Backups\Console\StatusCommand;
use App\Backups\Console\TickCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Backups module: the app's side of pgBackRest (spec section 9): the test restore's checks, the key and
 * epochs kept on the backup disk, and the new epochs after a restore (D23).
 */
class BackupsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TickCommand::class, StatusCommand::class, AfterRestoreCommand::class]);
        }
    }
}

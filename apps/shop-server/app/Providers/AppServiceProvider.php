<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading, unknown attributes and silently dropped writes.
        Model::shouldBeStrict();

        // Dates never change in place, so a time read from a sale cannot be shifted by accident.
        Date::use(CarbonImmutable::class);

        // On a shop server, commands that wipe the database (migrate:fresh, db:wipe and the like) refuse to run.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // The browser tests serve the built assets while the development Vite server keeps running.
        $hot = config('app.vite_hot_file');
        if (is_string($hot) && $hot !== '') {
            Vite::useHotFile($hot);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Organisation;

use Illuminate\Support\ServiceProvider;

/**
 * The Organisation module: the shop, its branches and their terminals (D23).
 */
class OrganisationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BranchKey::class, fn () => new BranchKey(config()->string('organisation.key_dir')));
    }
}

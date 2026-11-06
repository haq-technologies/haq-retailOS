<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Catalogue\Console\SeedCatalogueCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Catalogue module: categories and templates, pick-lists, brands, models, items and the one name
 * routine (spec sections 5 and 6) (D23).
 */
class CatalogueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SeedCatalogueCommand::class]);
        }
    }
}

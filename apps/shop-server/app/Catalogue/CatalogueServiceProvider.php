<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Catalogue\Console\SeedCatalogueCommand;
use App\Settings\Definition;
use App\Settings\Registry;
use Illuminate\Support\ServiceProvider;

/**
 * The Catalogue module: categories and templates, pick-lists, brands, models, items and the one name
 * routine (spec sections 5 and 6) (D23).
 */
class CatalogueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            // Whether a cashier creates items at the counter or sells a typed line (spec section 6) [F20].
            $registry->define(new Definition('catalogue.cashier_item_creation', 'Cashiers can create items', 'on', ['required', 'in:on,request_only'], lockedByDefault: true, source: 'spec section 6'));
            // How many times a search term must lead to an item before it becomes an alias [F13].
            $registry->define(new Definition('catalogue.alias_activation', 'Searches before a term becomes an alias', 3, ['required', 'integer', 'min:1', 'max:20'], lockedByDefault: true, source: 'F13'));

            return $registry;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SeedCatalogueCommand::class]);
        }
    }
}

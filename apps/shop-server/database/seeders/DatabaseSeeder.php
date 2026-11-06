<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Catalogue\StarterCatalogue;
use Illuminate\Database\Seeder;

/*
 * Loads the starter catalogue (v1.1, from docs/catalogue). Loading it twice changes nothing (gate G2).
 */
class DatabaseSeeder extends Seeder
{
    public function run(StarterCatalogue $catalogue): void
    {
        $catalogue->load(config()->string('catalogue.seed'));
    }
}

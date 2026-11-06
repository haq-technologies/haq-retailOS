<?php

declare(strict_types=1);

namespace App\Catalogue\Console;

use App\Catalogue\StarterCatalogue;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Loads the starter catalogue at install (spec section 6). Safe to run again: a second run changes nothing.
 */
#[AsCommand(name: 'catalogue:seed')]
class SeedCatalogueCommand extends Command
{
    protected $signature = 'catalogue:seed {--path= : The seed file (default: the CATALOGUE_SEED setting)}';

    protected $description = 'Load the starter catalogue; a second run changes nothing';

    public function handle(StarterCatalogue $catalogue): int
    {
        $path = $this->option('path');
        $created = $catalogue->load(is_string($path) && $path !== '' ? $path : config()->string('catalogue.seed'));

        if (array_sum($created) === 0) {
            $this->components->info('Nothing to change: the starter catalogue is already loaded.');

            return self::SUCCESS;
        }
        $this->components->info('The starter catalogue is loaded:');
        foreach ($created as $kind => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $kind), (string) $count);
        }

        return self::SUCCESS;
    }
}

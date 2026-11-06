<?php

declare(strict_types=1);

namespace App\Settings\Console;

use App\Settings\Profile\InvalidProfile;
use App\Settings\Profile\Profile;
use App\Settings\Profile\ProfileApplier;
use App\Settings\Registry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Applies a shop profile at install (spec section 6, phase 1). Safe to run again: a second run changes
 * nothing, and nothing is ever deleted.
 */
#[AsCommand(name: 'shop:apply-profile')]
class ApplyProfileCommand extends Command
{
    protected $signature = 'shop:apply-profile {path : The profile file}';

    protected $description = 'Apply a shop profile: the shop, this branch, its counters and the settings';

    public function handle(Registry $registry, ProfileApplier $applier): int
    {
        try {
            $profile = Profile::fromFile((string) $this->argument('path'), $registry);
        } catch (InvalidProfile $e) {
            $this->components->error('The profile was not applied, because it is not valid:');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $changes = $applier->apply($profile);
        if ($changes === []) {
            $this->components->info('Nothing to change: this server already matches the profile.');

            return self::SUCCESS;
        }

        $this->components->info('The profile is applied:');
        $this->components->bulletList($changes);

        return self::SUCCESS;
    }
}

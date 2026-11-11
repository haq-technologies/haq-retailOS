<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Users;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deactivates a person who leaves the shop, for HAQ support (D74, QA finding P15). Nobody is ever deleted:
 * their tile goes, their PIN stops working, and anyone signed in as them is signed out at their next tap. The
 * last active owner cannot be deactivated. `sudo haq deactivate` runs it.
 */
#[AsCommand(name: 'shop:deactivate-user')]
class DeactivateUserCommand extends Command
{
    use FindsPerson;

    protected $signature = 'shop:deactivate-user {person : The name on the tile, or the person\'s ID}';

    protected $description = 'Deactivate a person; nobody is ever deleted';

    public function handle(Users $users): int
    {
        $user = $this->person((string) $this->argument('person'));
        if ($user === null) {
            return self::FAILURE;
        }
        if (! $user->isActive()) {
            $this->components->info("{$user->name} was already deactivated.");

            return self::SUCCESS;
        }

        try {
            $users->deactivate($user);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->components->info("{$user->name} ({$user->role->label()}) is deactivated. Their tile is gone and their PIN no longer works.");

        return self::SUCCESS;
    }
}

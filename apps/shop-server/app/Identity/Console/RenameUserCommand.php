<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Users;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Corrects the name on a person's tile (D113, finding DP7 of the privacy checks): a person may have a wrong
 * name put right, and until now the only way was to deactivate them and add them again, which split their
 * history. The receipts already printed keep the old name; the audit trail records both. `sudo haq rename`
 * runs it, and `haq-helper server rename` on a Windows server.
 */
#[AsCommand(name: 'shop:rename-user')]
class RenameUserCommand extends Command
{
    use FindsPerson;

    protected $signature = 'shop:rename-user {person : The name on the tile, or the person\'s ID} {name : The right name}';

    protected $description = 'Correct the name on a person\'s tile';

    public function handle(Users $users): int
    {
        $user = $this->person((string) $this->argument('person'));
        if ($user === null) {
            return self::FAILURE;
        }
        $old = $user->name;
        try {
            $users->rename($user, (string) $this->argument('name'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->components->info($old === $user->name ? "{$old} already has that name." : "{$old} is now {$user->name} on every tile and every new receipt.");

        return self::SUCCESS;
    }
}

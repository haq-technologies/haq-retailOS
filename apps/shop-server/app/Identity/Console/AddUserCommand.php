<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Role;
use App\Identity\Users;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;

/**
 * Adds a person with a name tile and PIN, at install (spec section 6, phase 1). The PIN is asked for
 * twice without echoing; --pin exists only for scripted test installs.
 */
#[AsCommand(name: 'shop:add-user')]
class AddUserCommand extends Command
{
    protected $signature = 'shop:add-user {name : The name on the tile} {--role=cashier : cashier, manager, storekeeper or owner} {--pin= : Scripted installs only: the PIN}';

    protected $description = 'Add a person who signs in with a name tile and PIN';

    public function handle(Users $users): int
    {
        $role = Role::tryFrom((string) $this->option('role'));
        if ($role === null) {
            $this->components->error('The role must be cashier, manager, storekeeper or owner.');

            return self::INVALID;
        }

        $pin = $this->option('pin');
        if (! is_string($pin) || $pin === '') {
            [$min, $max] = $role->pinLength();
            $hint = $min === $max ? "{$min} digits" : "{$min} to {$max} digits";
            $pin = password("PIN for {$this->argument('name')} ({$hint})", required: true);
            if (password('The same PIN again', required: true) !== $pin) {
                $this->components->error('The two PINs differ. Nothing was added.');

                return self::FAILURE;
            }
        }

        $user = $users->add((string) $this->argument('name'), $role, $pin);
        $this->components->info("Added {$user->name} ({$role->label()}).");

        return self::SUCCESS;
    }
}

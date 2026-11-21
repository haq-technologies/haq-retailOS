<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Role;
use App\Identity\Users;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;

/**
 * Adds a person with a name tile and PIN, at install (spec section 6, phase 1). The PIN is asked for
 * twice without echoing. --pin is for scripted installs; the Windows helper asks the PIN itself, because its
 * runner has no terminal (D91), and sends it on standard input with --pin-stdin (D99).
 */
#[AsCommand(name: 'shop:add-user')]
class AddUserCommand extends Command
{
    use GivenPin;

    protected $signature = 'shop:add-user {name : The name on the tile} {--role=cashier : cashier, manager, storekeeper or owner} {--pin= : Scripted installs: the PIN} {--pin-stdin : The Windows helper: the PIN, as the first line of standard input}';

    protected $description = 'Add a person who signs in with a name tile and PIN';

    public function handle(Users $users): int
    {
        $role = Role::tryFrom((string) $this->option('role'));
        if ($role === null) {
            $this->components->error('The role must be cashier, manager, storekeeper or owner.');

            return self::INVALID;
        }

        $pin = $this->givenPin();
        if ($pin === null) {
            [$min, $max] = $role->pinLength();
            $hint = $min === $max ? "{$min} digits" : "{$min} to {$max} digits";
            $pin = password("PIN for {$this->argument('name')} ({$hint})", required: true);
            if (password('The same PIN again', required: true) !== $pin) {
                $this->components->error('The two PINs differ. Nothing was added.');

                return self::FAILURE;
            }
        }

        // A PIN of the wrong length is a typing mistake, not a server error: say so plainly, as shop:set-pin
        // does, and keep it out of the log (finding LG9).
        try {
            $user = $users->add((string) $this->argument('name'), $role, $pin);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage().' Nobody was added.');

            return self::FAILURE;
        }
        $this->components->info("Added {$user->name} ({$role->label()}).");

        return self::SUCCESS;
    }
}

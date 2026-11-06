<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Models\User;
use App\Identity\Users;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shop:deactivate-user')]
class DeactivateUserCommand extends Command
{
    protected $signature = 'shop:deactivate-user {id : The person\'s ID}';

    protected $description = 'Deactivate a person; nobody is ever deleted';

    public function handle(Users $users): int
    {
        $user = User::query()->find((string) $this->argument('id'));
        if ($user === null) {
            $this->components->error('There is no such person.');

            return self::FAILURE;
        }
        $users->deactivate($user);
        $this->components->info("{$user->name} is deactivated.");

        return self::SUCCESS;
    }
}

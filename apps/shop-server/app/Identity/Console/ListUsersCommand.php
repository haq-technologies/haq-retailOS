<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Models\User;
use App\Settings\Settings;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Everyone with a name tile, for HAQ support (D74, QA finding P15): the active people first, each with their
 * role, when their PIN was last set and their ID. `sudo haq users` runs it.
 */
#[AsCommand(name: 'shop:list-users')]
class ListUsersCommand extends Command
{
    protected $signature = 'shop:list-users';

    protected $description = 'List everyone with a name tile: role, status, when the PIN was set, and ID';

    public function handle(Settings $settings): int
    {
        $zone = $settings->get('business.timezone');
        $zone = is_string($zone) ? $zone : 'Africa/Lagos';
        $people = User::query()->orderByRaw('deactivated_at IS NOT NULL')->orderBy('name')->orderBy('id')->get();
        if ($people->isEmpty()) {
            $this->components->info('Nobody has a name tile yet. Add the owner first: sudo haq add-user "Name" owner');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Role', 'Status', 'PIN set', 'ID'], $people->map(fn (User $u) => [
            $u->name,
            $u->role->label(),
            $u->isActive() ? 'Active' : 'Deactivated',
            $u->pin_changed_at?->setTimezone($zone)->format('j M Y') ?? '',
            $u->id,
        ])->all());

        return self::SUCCESS;
    }
}

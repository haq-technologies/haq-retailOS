<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Throttle;
use App\Identity\Users;
use App\Settings\Settings;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;

/**
 * Gives a person a new PIN, for HAQ support (D74, QA finding P15): when a PIN is forgotten or someone else
 * has seen it. The PIN is asked for twice without echoing, as at install; --pin exists only for scripted
 * test installs. The old PIN stops working at once, and the change is audited. `sudo haq pin` runs it.
 */
#[AsCommand(name: 'shop:set-pin')]
class SetPinCommand extends Command
{
    use FindsPerson;

    protected $signature = 'shop:set-pin {person : The name on the tile, or the person\'s ID} {--pin= : Scripted installs only: the new PIN}';

    protected $description = 'Give a person a new PIN; the old one stops working';

    public function handle(Users $users, Throttle $throttle, Settings $settings): int
    {
        $user = $this->person((string) $this->argument('person'));
        if ($user === null) {
            return self::FAILURE;
        }
        if (! $user->isActive()) {
            $this->components->error("{$user->name} is deactivated, so they have no PIN to change.");

            return self::FAILURE;
        }

        $pin = $this->option('pin');
        if (! is_string($pin) || $pin === '') {
            [$min, $max] = $user->role->pinLength();
            $hint = $min === $max ? "{$min} digits" : "{$min} to {$max} digits";
            $pin = password("New PIN for {$user->name} ({$hint})", required: true);
            if (password('The same PIN again', required: true) !== $pin) {
                $this->components->error('The two PINs differ. The PIN was not changed.');

                return self::FAILURE;
            }
        }

        try {
            $users->setPin($user, $pin);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage().' The PIN was not changed.');

            return self::FAILURE;
        }
        $this->components->info("{$user->name}'s PIN is changed ({$user->role->label()}). The old PIN no longer works.");

        $lockedUntil = $throttle->lockedUntil($user->id);
        if ($lockedUntil !== null) {
            $zone = $settings->get('business.timezone');
            $at = $lockedUntil->setTimezone(is_string($zone) ? $zone : 'Africa/Lagos')->format('H:i');
            $this->components->warn("{$user->name} is still locked after wrong PINs until {$at}; the new PIN works from then.");
        }

        return self::SUCCESS;
    }
}

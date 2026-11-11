<?php

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Console\AddUserCommand;
use App\Identity\Console\DeactivateUserCommand;
use App\Identity\Console\EnrolTerminalCommand;
use App\Identity\Console\ListUsersCommand;
use App\Identity\Console\SetPinCommand;
use App\Settings\Definition;
use App\Settings\Registry;
use Illuminate\Support\ServiceProvider;

/**
 * The Identity module: name tiles and PINs, acting-user tokens, throttling, bound approvals and terminal
 * enrolment (spec section 3) (D23).
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            foreach (self::definitions() as $definition) {
                $registry->define($definition);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([AddUserCommand::class, EnrolTerminalCommand::class, DeactivateUserCommand::class, ListUsersCommand::class, SetPinCommand::class]);
        }
    }

    /**
     * Identity's settings. The spec sets the attempt counts and the bind limit; the product owner accepted
     * the lockout cooldown, the elevated-session limit and the backoff curve (D28).
     *
     * @return list<Definition>
     */
    public static function definitions(): array
    {
        $minutes = ['required', 'integer', 'min:0', 'max:60'];

        return [
            new Definition('identity.pin_mode', 'Cashier identity', 'tile_pin', ['required', 'in:tile_pin'], lockedByDefault: true, source: 'spec section 3, mode A; mode B comes with the console'),
            new Definition('identity.idle_lock_minutes', 'Idle lock (minutes; 0 turns it off)', 3, $minutes, lockedByDefault: true, source: 'S6'),
            new Definition('identity.elevated_idle_minutes', 'Manager or owner idle limit (minutes)', 2, ['required', 'integer', 'min:1', 'max:60'], lockedByDefault: true, source: 'spec section 3; D28'),
            new Definition('identity.lockout_attempts', 'Wrong PINs before a lockout', 5, ['required', 'integer', 'min:3', 'max:10'], lockedByDefault: true, source: 'spec section 3'),
            new Definition('identity.lockout_minutes', 'Lockout cooldown (minutes)', 15, ['required', 'integer', 'min:1', 'max:240'], lockedByDefault: true, source: 'spec section 3; D28'),
            new Definition('identity.terminal_backoff_failures', 'Wrong PINs on one counter before backoff', 10, ['required', 'integer', 'min:5', 'max:50'], lockedByDefault: true, source: 'spec section 3'),
            new Definition('identity.terminal_backoff_window_minutes', 'Backoff window (minutes)', 10, ['required', 'integer', 'min:1', 'max:60'], lockedByDefault: true, source: 'spec section 3'),
            new Definition('identity.approval_bind_seconds', 'Seconds to bind an approval', 60, ['required', 'integer', 'min:10', 'max:300'], lockedByDefault: true, source: 'S3'),
        ];
    }
}

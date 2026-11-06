<?php

declare(strict_types=1);

namespace App\Shifts;

use App\Settings\Definition;
use App\Settings\Registry;
use App\Shifts\Console\AutoCloseCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Shifts module: shifts per drawer and the business day (spec section 9) (D23).
 */
class ShiftsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            // The shop's closing time (HH:MM, in the business timezone): a shift still open four hours
            // later closes as unclosed [S12]. The owner sets it in Shop settings (D37); until then
            // nothing closes by itself.
            $registry->define(new Definition('shifts.closing_time', 'Closing time', null,
                ['nullable', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'], lockedByDefault: false, source: 'S12, D37',
                ownerFacing: true, ownerKind: 'time', help: 'A shift still open 4 hours after closing time closes by itself, marked as not counted. Leave it empty to turn this off.'));

            return $registry;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AutoCloseCommand::class]);
        }
    }
}

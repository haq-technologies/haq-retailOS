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
            // Paid-outs, drops and top-ups: a cashier records them up to this amount; above it a manager approves
            // (spec section 9) [F19, D68]. Until the owner sets it, every one needs a manager, as discards do (D38).
            $registry->define(new Definition('cash.approval_limit_kobo', 'Cash out of or into the drawer without a manager', 0, ['required', 'integer', 'min:0', 'max:100000000'],
                lockedByDefault: false, source: 'F19, D68', ownerFacing: true, ownerKind: 'money',
                help: 'A cashier may take a paid-out, a drop to the safe or a top-up up to this amount, with a reason. Above it a manager or you must type a PIN. At ₦0 every one needs a PIN.'));
            // A drawer whose count at the close differs from what was expected by more than this is flagged for the owner [F19, D68].
            $registry->define(new Definition('cash.variance_flag_kobo', 'Drawer difference that is flagged', 100_000, ['required', 'integer', 'min:0', 'max:100000000'],
                lockedByDefault: false, source: 'F19, D68', ownerFacing: true, ownerKind: 'money',
                help: 'When a drawer is closed for the day and its count is over or short by more than this, you see it in Flags and on the dashboard.'));

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

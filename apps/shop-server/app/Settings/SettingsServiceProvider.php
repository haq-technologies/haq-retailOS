<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings\Console\ApplyProfileCommand;
use App\Settings\Console\MakeProfileCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The Settings module: settings, their history and the shop profile tool (spec section 11) (D23).
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function (): Registry {
            $registry = new Registry;
            foreach (self::definitions() as $definition) {
                $registry->define($definition);
            }

            return $registry;
        });
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeProfileCommand::class, ApplyProfileCommand::class]);
        }
    }

    /**
     * The settings this module owns. Other modules define theirs in their own providers.
     *
     * @return list<Definition>
     */
    public static function definitions(): array
    {
        $line = ['nullable', 'string', 'max:60'];

        return [
            // The receipt header and footer: a shop setting only the sysadmin changes, with a branch
            // override for the address and phone, so each branch's receipts show where they were printed (D14).
            new Definition('receipt.shop_name', 'Receipt: shop name', null, ['required', 'string', 'max:40'], lockedByDefault: true, source: 'D14'),
            new Definition('receipt.address', 'Receipt: address', null, $line, ['shop', 'branch'], lockedByDefault: true, source: 'D14'),
            new Definition('receipt.phone', 'Receipt: phone', null, ['nullable', 'string', 'max:40'], ['shop', 'branch'], lockedByDefault: true, source: 'D14'),
            new Definition('receipt.footer', 'Receipt: footer (returns wording)', null, ['nullable', 'string', 'max:200'], lockedByDefault: true, source: 'D14'),

            // The business day's timezone; times are stored in UTC (spec section 4) [F15].
            new Definition('business.timezone', 'Business day timezone', 'Africa/Lagos', ['required', 'string', 'timezone:all'], lockedByDefault: true, source: 'spec section 11'),
        ];
    }
}

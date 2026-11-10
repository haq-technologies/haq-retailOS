<?php

declare(strict_types=1);

namespace App\Printing;

use App\Printing\Console\FakePrinterCommand;
use App\Sales\Events\SaleFinished;
use App\Settings\Definition;
use App\Settings\Registry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The Printing module: receipts to network printers, pending print, reprints and the drawer log (D23).
 */
class PrintingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PrinterConnection::class, NetworkPrinter::class);
        $this->app->extend(Registry::class, function (Registry $registry): Registry {
            // What prints before the total. Thermal printers' character sets rarely hold ₦, so "NGN" unless
            // this setting is changed (D31, D60).
            $registry->define(new Definition('receipt.currency', 'Currency before the receipt total', 'NGN', ['required', 'in:NGN,N,₦'], lockedByDefault: true, source: 'D31'));

            return $registry;
        });
    }

    public function boot(): void
    {
        // Printing happens after the sale commits, never inside its transaction [F5].
        Event::listen(SaleFinished::class, fn (SaleFinished $event) => PrintReceipt::dispatch($event->printJobId));

        $this->loadRoutesFrom(__DIR__.'/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([FakePrinterCommand::class]);
        }
    }
}

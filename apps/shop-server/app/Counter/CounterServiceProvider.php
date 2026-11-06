<?php

declare(strict_types=1);

namespace App\Counter;

use App\Sales\Events\SaleFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The Counter module: the counter's copy of the catalogue, what it learns from selling, and (later) the
 * counter page itself (spec sections 4, 6 and 15) (D23).
 */
class CounterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        Event::listen(SaleFinished::class, [LearnFromSale::class, 'handle']);
    }
}

<?php

declare(strict_types=1);

namespace App\Printing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The print queue's job: sends one print job to its counter's printer, trying up to three times.
 */
final class PrintReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = Printing::MAX_ATTEMPTS;

    /** @var list<int> */
    public array $backoff = [2, 5];

    public function __construct(public string $printJobId)
    {
        $this->onQueue('print');
    }

    public function handle(Printing $printing): void
    {
        if (! $printing->print($this->printJobId) && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 5);
        }
    }
}

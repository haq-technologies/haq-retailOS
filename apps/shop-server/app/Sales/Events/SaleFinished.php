<?php

declare(strict_types=1);

namespace App\Sales\Events;

/**
 * A sale has committed (spec section 7) [F5]. Fired after the transaction, never inside it, so a deadlock
 * retry can never print twice. Printing listens.
 */
final readonly class SaleFinished
{
    public function __construct(public string $saleId, public string $printJobId) {}
}

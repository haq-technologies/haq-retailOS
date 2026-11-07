<?php

declare(strict_types=1);

namespace App\Sales;

/**
 * The server was restored and this counter has not reported its journal yet (spec section 4) [S14]. The
 * counter reports it at once and finishes the sale again; nothing was written.
 */
final class JournalNeeded extends SaleRefused
{
    public function __construct()
    {
        parent::__construct('The shop server was restored. This counter is checking its last sales; finish again in a moment.');
    }
}

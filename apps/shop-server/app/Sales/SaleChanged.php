<?php

declare(strict_types=1);

namespace App\Sales;

/**
 * The same idempotency key arrived with a different cart (spec section 7) [F5]. Nothing is created.
 */
final class SaleChanged extends SaleRefused
{
    public function __construct()
    {
        parent::__construct('This sale changed. Review it and finish again.');
    }
}

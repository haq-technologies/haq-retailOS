<?php

declare(strict_types=1);

namespace App\Sales;

/**
 * A line's price needs the cashier's check before the sale can finish (spec section 7) [S5]: "confirm"
 * a price outside the usual range, or "retype" a price far above it. The counter shows that prompt for
 * the line and sends the sale again.
 */
final class PriceNeedsCheck extends SaleRefused
{
    public function __construct(string $message, public readonly string $clientLineId, public readonly string $check)
    {
        parent::__construct($message);
    }
}

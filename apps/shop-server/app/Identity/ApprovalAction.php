<?php

declare(strict_types=1);

namespace App\Identity;

/**
 * What a manager's PIN can approve (spec sections 3, 7 and 9). The counter asks for an approval for one
 * of these, bound to one line, item and price.
 */
enum ApprovalAction: string
{
    case PriceBelowFloor = 'price_below_floor';
    case BelowRangeConfirmLimit = 'below_range_confirm_limit';
    case DiscardParkedSale = 'discard_parked_sale';
    case Return = 'return';
    case Exchange = 'exchange';
    case Void = 'void';
    case CreditSale = 'credit_sale';
    case PaidOut = 'paid_out';

    /** A price approval names its cart line, item and price, and is bound to them when it is granted [S3]. */
    public function isPrice(): bool
    {
        return $this === self::PriceBelowFloor || $this === self::BelowRangeConfirmLimit;
    }
}

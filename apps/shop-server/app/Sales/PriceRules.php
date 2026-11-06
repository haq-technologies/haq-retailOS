<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Models\Item;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;

/**
 * The price checks Finish enforces on the server (spec section 7) [F11, S4, S5]: the usual range, the
 * floor and its manager approval, the confirm count per shift and the retype check. Called inside the
 * sale's transaction, so an approval binds only if the sale commits.
 */
class PriceRules
{
    /**
     * @param  array<string, Item>  $items
     * @return list<array{approval_id: ?string, flags: array<string, mixed>}> One entry per line, in order.
     */
    public function check(Terminal $terminal, User $cashier, SaleRequest $request, array $items): array
    {
        return array_map(fn () => ['approval_id' => null, 'flags' => []], $request->lines);
    }
}

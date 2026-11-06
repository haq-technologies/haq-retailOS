<?php

declare(strict_types=1);

namespace App\Cost;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cost entries (spec section 8) [F14]. In the pilot and MVP a sale line takes the owner's current cost
 * entry for the item; with none, its cost snapshot is NULL and the line waits in the Needs-cost queue.
 * Cost never leaves the server for a role that may not see it.
 */
final class Costs
{
    public static function current(string $itemId, ?CarbonImmutable $at = null): ?int
    {
        $cost = DB::table('cost_entries')->where('item_id', $itemId)
            ->where('effective_from', '<=', ($at ?? CarbonImmutable::now())->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('effective_from')->value('unit_cost_kobo');

        return is_numeric($cost) ? (int) $cost : null;
    }
}

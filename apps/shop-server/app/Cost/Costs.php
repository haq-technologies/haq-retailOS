<?php

declare(strict_types=1);

namespace App\Cost;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cost entries (spec section 8) [F14]. In the pilot and MVP a sale line takes the owner's current cost
 * entry for the item; with none, its cost snapshot is NULL and the line waits in the Needs-cost queue.
 * Cost never leaves the server for a role that may not see it.
 */
final class Costs
{
    /**
     * Writes a sale line's estimate (cost_resolutions). The newest estimate of a line is the one that counts,
     * so each is stamped after the line's last, whatever the server clock says: a clock that steps back
     * must never make an undo older than the estimate it undoes [F15, D48].
     *
     * @param  array{id: string, sale_line_id: string, item_id: string, unit_cost_kobo: ?int, source: string, basis: string, cost_entry_id: string, resolved_by: ?string, created_at: string}  $row
     */
    public static function resolve(array $row): void
    {
        DB::insert(<<<'SQL'
            INSERT INTO cost_resolutions (id, sale_line_id, item_id, unit_cost_kobo, source, basis, cost_entry_id, resolved_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GREATEST(?::timestamptz, (SELECT max(created_at) + interval '1 microsecond' FROM cost_resolutions WHERE sale_line_id = ?)))
            SQL, [$row['id'], $row['sale_line_id'], $row['item_id'], $row['unit_cost_kobo'], $row['source'], $row['basis'], $row['cost_entry_id'],
            $row['resolved_by'], $row['created_at'], $row['sale_line_id']]);
    }

    /**
     * An item's cost entries that count: not an undo, and not undone (D48). So the cost in force never
     * depends on when an undo was stamped, even with a clock that stepped back.
     */
    public static function inForce(string $itemId): Builder
    {
        return DB::table('cost_entries')->where('item_id', $itemId)->whereNull('undoes_id')
            ->whereNotExists(fn ($q) => $q->from('cost_entries as x')->whereColumn('x.undoes_id', 'cost_entries.id'));
    }

    public static function current(string $itemId, ?CarbonImmutable $at = null): ?int
    {
        $cost = self::inForce($itemId)
            ->where('effective_from', '<=', ($at ?? CarbonImmutable::now())->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('effective_from')->orderByDesc('id')->value('unit_cost_kobo');

        return is_numeric($cost) ? (int) $cost : null;
    }
}

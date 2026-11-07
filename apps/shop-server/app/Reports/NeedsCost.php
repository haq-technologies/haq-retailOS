<?php

declare(strict_types=1);

namespace App\Reports;

use App\Catalogue\Merges;
use App\Catalogue\NameBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The Needs-cost queue (spec section 8) [F14]: items with sales that have no cost, at sale or estimated
 * since, highest revenue first.
 */
final class NeedsCost
{
    /**
     * @return array{items: list<array{item_id: string, name: string, sales: int, revenue_kobo: int, first_at: string}>, total_items: int, total_revenue_kobo: int, since: ?string}
     */
    public static function queue(?string $search = null, int $limit = 50): array
    {
        $bindings = [];
        $filter = '';
        if ($search !== null && trim($search) !== '') {
            $filter = 'AND i.search_text LIKE ?';
            $bindings[] = '%'.str_replace(['%', '_'], ['\%', '\_'], NameBuilder::normalise($search)).'%';
        }
        // A merged item's sales count under the item it was merged into [F18].
        $roots = Merges::rootsCte();
        /** @var list<object{item_id: string, name: string, sales: int|string, revenue_kobo: int|string, first_at: string}> $rows */
        $rows = DB::select(<<<SQL
            WITH RECURSIVE {$roots}
            SELECT i.id AS item_id, i.display_name AS name, count(*) AS sales, sum(l.line_total_kobo) AS revenue_kobo, min(s.recorded_at) AS first_at
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN item_roots ir ON ir.item_id = l.item_id JOIN items i ON i.id = ir.root_id
            WHERE s.kind = 'sale' AND l.cost_snapshot_kobo IS NULL
              AND NOT EXISTS (SELECT 1 FROM cost_resolutions r WHERE r.sale_line_id = l.id) {$filter}
            GROUP BY i.id, i.display_name
            ORDER BY revenue_kobo DESC, name
            SQL, $bindings);

        $items = array_map(fn (object $r) => ['item_id' => $r->item_id, 'name' => $r->name, 'sales' => (int) $r->sales, 'revenue_kobo' => (int) $r->revenue_kobo, 'first_at' => $r->first_at], $rows);
        $since = DB::table('sales')->where('kind', 'sale')->min('recorded_at');

        return [
            'items' => array_slice($items, 0, $limit),
            'total_items' => count($items),
            'total_revenue_kobo' => array_sum(array_column($items, 'revenue_kobo')),
            'since' => is_string($since) ? $since : null,
        ];
    }
}

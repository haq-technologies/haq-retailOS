<?php

declare(strict_types=1);

namespace App\Reports;

use App\Catalogue\Merges;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * CSV exports of items and sales (spec section 12) [F10], opening cleanly in Excel (see Csv). They never
 * contain cost for a role that may not see it: the cost columns are left out, not blanked. A merged item's
 * sales show the item they now count under beside the name the receipt printed.
 */
final class Exports
{
    private const STATUS = [
        'active' => 'Sold', 'pending_review' => 'Waiting for review', 'pending_category' => 'Waiting for a category',
        'merged' => 'Merged', 'archived' => 'Archived',
    ];

    private const SOURCE = [
        'starter' => 'Starter catalogue', 'owner' => 'Back office', 'cashier' => 'At the counter', 'typed' => 'Typed line at the counter',
        'import' => 'Imported', 'generated' => 'At the counter',
    ];

    /** @return list<string> */
    public static function itemsHeader(bool $withCost): array
    {
        return ['Item code', 'Name', 'Category', 'Brand', 'Status', 'Added', 'Retail set price (NGN)', 'Wholesale set price (NGN)', 'Barcodes', 'Sold (quantity)',
            'Counts under', ...($withCost ? ['Cost (NGN)'] : []), 'Created'];
    }

    /**
     * @return Generator<int, list<string|int|null>>
     */
    public static function items(bool $withCost, string $zone): Generator
    {
        $rows = DB::table('items as i')->join('categories as c', 'c.id', '=', 'i.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'i.brand_id')->leftJoin('items as m', 'm.id', '=', 'i.merged_into_id')
            ->orderBy('c.name')->orderBy('i.display_name')
            ->select(['i.id', 'i.item_code', 'i.display_name', 'c.name as category', 'b.name as brand', 'i.brand_text', 'i.status', 'i.source', 'i.created_at', 'm.display_name as merged_into',
                DB::raw("(SELECT p.price_kobo FROM item_prices p WHERE p.item_id = i.id AND p.customer_type = 'retail' ORDER BY p.effective_from DESC, p.id DESC LIMIT 1) AS retail"),
                DB::raw("(SELECT p.price_kobo FROM item_prices p WHERE p.item_id = i.id AND p.customer_type = 'wholesale' ORDER BY p.effective_from DESC, p.id DESC LIMIT 1) AS wholesale"),
                DB::raw("(SELECT string_agg(bc.code, ' ' ORDER BY bc.code) FROM barcodes bc WHERE bc.item_id = i.id AND bc.status <> 'disabled') AS codes"),
                DB::raw("(SELECT coalesce(sum(l.qty), 0) FROM sale_lines l JOIN sales s ON s.id = l.sale_id WHERE l.item_id = i.id AND s.kind IN ('sale', 'back_entry', 'void', 'return')) AS sold"),
                DB::raw('(SELECT ce.unit_cost_kobo FROM cost_entries ce WHERE ce.item_id = i.id AND ce.effective_from <= clock_timestamp() AND ce.undoes_id IS NULL AND NOT EXISTS (SELECT 1 FROM cost_entries x WHERE x.undoes_id = ce.id) ORDER BY ce.effective_from DESC, ce.id DESC LIMIT 1) AS cost')])
            ->cursor();

        foreach ($rows as $r) {
            /** @var object{item_code: string, display_name: string, category: string, brand: ?string, brand_text: ?string, status: string, source: string, created_at: string, merged_into: ?string, retail: int|string|null, wholesale: int|string|null, codes: ?string, sold: int|string, cost: int|string|null} $r */
            yield [
                $r->item_code, $r->display_name, $r->category, $r->brand ?? $r->brand_text, self::STATUS[$r->status] ?? $r->status, self::SOURCE[$r->source] ?? $r->source,
                Csv::naira($r->retail === null ? null : (int) $r->retail), Csv::naira($r->wholesale === null ? null : (int) $r->wholesale),
                $r->codes, (int) $r->sold, $r->merged_into, ...($withCost ? [Csv::naira($r->cost === null ? null : (int) $r->cost)] : []),
                CarbonImmutable::parse($r->created_at)->setTimezone($zone)->format('Y-m-d'),
            ];
        }
    }

    /** @return list<string> */
    public static function salesHeader(bool $withCost): array
    {
        return ['Business day', 'Time', 'Receipt', 'Counter', 'Cashier', 'Customer', 'Item code', 'Item (as printed)', 'Counts under', 'Quantity',
            'Unit price (NGN)', 'Line total (NGN)', 'Paid by', 'Price check',
            ...($withCost ? ['Unit cost (NGN)', 'Cost', 'Line profit (NGN)'] : [])];
    }

    /**
     * One row per sale line in the period, in time order.
     *
     * @return Generator<int, list<string|int|null>>
     */
    public static function sales(Period $period, bool $withCost, string $zone): Generator
    {
        $roots = Merges::rootsCte();
        $rows = DB::cursor(<<<SQL
            WITH RECURSIVE {$roots}
            SELECT s.business_day, s.recorded_at, s.receipt_number, t.name AS counter, u.name AS cashier, s.customer_type,
                   i.item_code, l.name_snapshot, CASE WHEN r.root_id <> l.item_id THEN ri.display_name END AS counts_under,
                   l.qty, l.unit_price_kobo, l.line_total_kobo, l.flags, l.cost_snapshot_kobo,
                   (SELECT c.unit_cost_kobo FROM cost_resolutions c WHERE c.sale_line_id = coalesce(l.reverses_line_id, l.id) ORDER BY c.created_at DESC, c.id DESC LIMIT 1) AS estimate,
                   (SELECT string_agg(DISTINCT p.method, ' + ') FROM payments p WHERE p.sale_id = s.id) AS paid_by
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN terminals t ON t.id = s.terminal_id JOIN users u ON u.id = s.cashier_id
            JOIN items i ON i.id = l.item_id JOIN item_roots r ON r.item_id = l.item_id JOIN items ri ON ri.id = r.root_id
            WHERE s.kind IN ('sale', 'back_entry', 'void', 'return') AND s.recorded_at >= ? AND s.recorded_at < ?
            ORDER BY s.recorded_at, s.receipt_number, l.line_no
            SQL, [$period->fromSql(), $period->toSql()]);

        foreach ($rows as $r) {
            /** @var object{business_day: string, recorded_at: string, receipt_number: string, counter: string, cashier: string, customer_type: string, item_code: string, name_snapshot: string, counts_under: ?string, qty: int|string, unit_price_kobo: int|string, line_total_kobo: int|string, flags: string, cost_snapshot_kobo: int|string|null, estimate: int|string|null, paid_by: ?string} $r */
            /** @var array{outside?: string, confirmed?: bool, retyped?: bool, below_floor?: bool, first_sale?: bool, back_entry?: bool, void?: bool, return?: bool} $flags */
            $flags = json_decode($r->flags, true) ?: [];
            $check = match (true) {
                // A void or a return: its lines are negative, so the export's totals net them (D68).
                ($flags['void'] ?? false) === true => 'Void',
                ($flags['return'] ?? false) === true => 'Return',
                ($flags['back_entry'] ?? false) === true => 'Re-entered after a restore',
                ($flags['below_floor'] ?? false) === true => 'Below the floor, approved',
                ($flags['retyped'] ?? false) === true => 'Very high, retyped',
                ($flags['confirmed'] ?? false) === true => 'Outside the usual range, confirmed',
                ($flags['first_sale'] ?? false) === true => 'First sale',
                default => '',
            };
            $row = [
                $r->business_day, CarbonImmutable::parse($r->recorded_at)->setTimezone($zone)->format('H:i'), $r->receipt_number, $r->counter, $r->cashier,
                $r->customer_type === 'wholesale' ? 'Wholesale' : 'Retail', $r->item_code, $r->name_snapshot, $r->counts_under, (int) $r->qty,
                Csv::naira((int) $r->unit_price_kobo), Csv::naira((int) $r->line_total_kobo), ucwords(str_replace('pos', 'POS', (string) $r->paid_by)), $check,
            ];
            if ($withCost) {
                $cost = $r->cost_snapshot_kobo ?? $r->estimate;
                $row[] = Csv::naira($cost === null ? null : (int) $cost);
                $row[] = $r->cost_snapshot_kobo !== null ? 'At sale' : ($r->estimate !== null ? 'Estimated' : 'Waiting for a cost');
                $row[] = $cost === null ? '' : Csv::naira((int) $r->line_total_kobo - (int) $r->qty * (int) $cost);
            }
            yield $row;
        }
    }
}

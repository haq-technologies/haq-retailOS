<?php

declare(strict_types=1);

namespace App\Reports;

use App\Catalogue\Merges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * First-sale prices (spec sections 7 and 13) [S4; wireframe FirstSalePrices]: items sold on the business
 * day with no usual price to check against, so the counter took the price the cashier typed. One row per
 * item and customer type, with each price sold and who sold it, and the set price if someone has set one
 * since (with Undo while it is that day's).
 */
final class FirstSales
{
    /**
     * @return list<array{item_id: string, name: string, customer_type: string, waiting_category: bool, sales: list<array{price_kobo: int, qty: int, cashier: string, at: string}>, prices: list<int>, set: ?array{price_kobo: int, by: ?string, at: string, today: bool}}>
     */
    public static function forDay(string $day, Period $span): array
    {
        $roots = Merges::rootsCte();
        /** @var list<object{item_id: string, name: string, status: string, customer_type: string, price: int|string, qty: int|string, cashier: string, at: string}> $lines */
        $lines = DB::select(<<<SQL
            WITH RECURSIVE {$roots}
            SELECT r.root_id AS item_id, i.display_name AS name, i.status, s.customer_type, l.unit_price_kobo AS price, l.qty, u.name AS cashier, s.recorded_at AS at
            FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN item_roots r ON r.item_id = l.item_id JOIN items i ON i.id = r.root_id
            JOIN users u ON u.id = s.cashier_id
            WHERE s.kind IN ('sale', 'back_entry') AND s.business_day = ? AND l.flags->>'first_sale' = 'true'
            ORDER BY s.recorded_at
            SQL, [$day]);

        $groups = [];
        foreach ($lines as $l) {
            $key = $l->item_id.'|'.$l->customer_type;
            $groups[$key] ??= ['item_id' => $l->item_id, 'name' => $l->name, 'customer_type' => $l->customer_type,
                'waiting_category' => $l->status === 'pending_category', 'sales' => [], 'prices' => [], 'set' => null];
            $groups[$key]['sales'][] = ['price_kobo' => (int) $l->price, 'qty' => (int) $l->qty, 'cashier' => $l->cashier, 'at' => $l->at];
        }

        foreach ($groups as $key => $group) {
            // The prices sold, the most used first, then the highest.
            $used = [];
            foreach ($group['sales'] as $sale) {
                $used[$sale['price_kobo']] = ($used[$sale['price_kobo']] ?? 0) + $sale['qty'];
            }
            uksort($used, fn (int $a, int $b) => [$used[$b], $b] <=> [$used[$a], $a]);
            $groups[$key]['prices'] = array_keys($used);

            /** @var object{price_kobo: int|string|null, effective_from: string, by: ?string}|null $set */
            $set = DB::table('item_prices as p')->leftJoin('users as u', 'u.id', '=', 'p.set_by')
                ->where('p.item_id', $group['item_id'])->where('p.customer_type', $group['customer_type'])
                ->orderByDesc('p.effective_from')->orderByDesc('p.id')->first(['p.price_kobo', 'p.effective_from', 'u.name as by']);
            if ($set !== null && $set->price_kobo !== null) {
                $groups[$key]['set'] = ['price_kobo' => (int) $set->price_kobo, 'by' => $set->by, 'at' => $set->effective_from,
                    'today' => $span->contains(CarbonImmutable::parse($set->effective_from))];
            }
        }

        return array_values($groups);
    }
}

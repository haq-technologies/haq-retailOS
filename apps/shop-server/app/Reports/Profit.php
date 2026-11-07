<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Profit in three buckets (spec section 8) [F14]: Confirmed at sale (cost known when sold), Estimated
 * (an estimate entered later, by backfill) and Waiting for a cost. No margin is ever guessed: revenue
 * waiting for a cost has no profit.
 */
final class Profit
{
    /**
     * @return array{revenue_kobo: int, confirmed: array{revenue_kobo: int, profit_kobo: int, percent: int}, estimated: array{revenue_kobo: int, profit_kobo: int, percent: int}, pending: array{revenue_kobo: int, percent: int}}
     */
    public static function buckets(?string $fromDay = null, ?string $toDay = null): array
    {
        $where = "s.kind IN ('sale', 'back_entry')";
        $bindings = [];
        if ($fromDay !== null) {
            $where .= ' AND s.business_day >= ?';
            $bindings[] = $fromDay;
        }
        if ($toDay !== null) {
            $where .= ' AND s.business_day <= ?';
            $bindings[] = $toDay;
        }
        /** @var object{c_rev: int|string, c_profit: int|string, e_rev: int|string, e_profit: int|string, p_rev: int|string} $r */
        $r = DB::selectOne(<<<SQL
            WITH lines AS (
                SELECT l.line_total_kobo AS revenue, l.qty, l.cost_snapshot_kobo AS snap,
                       (SELECT r.unit_cost_kobo FROM cost_resolutions r WHERE r.sale_line_id = l.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS est
                FROM sale_lines l JOIN sales s ON s.id = l.sale_id
                WHERE {$where}
            )
            SELECT coalesce(sum(revenue) FILTER (WHERE snap IS NOT NULL), 0) AS c_rev,
                   coalesce(sum(revenue - qty * snap) FILTER (WHERE snap IS NOT NULL), 0) AS c_profit,
                   coalesce(sum(revenue) FILTER (WHERE snap IS NULL AND est IS NOT NULL), 0) AS e_rev,
                   coalesce(sum(revenue - qty * est) FILTER (WHERE snap IS NULL AND est IS NOT NULL), 0) AS e_profit,
                   coalesce(sum(revenue) FILTER (WHERE snap IS NULL AND est IS NULL), 0) AS p_rev
            FROM lines
            SQL, $bindings);

        $c = (int) $r->c_rev;
        $e = (int) $r->e_rev;
        $p = (int) $r->p_rev;
        $total = $c + $e + $p;
        // Percentages that always add up to 100: the waiting share takes the rounding.
        $cp = $total === 0 ? 0 : intdiv($c * 100 + intdiv($total, 2), $total);
        $ep = $total === 0 ? 0 : intdiv($e * 100 + intdiv($total, 2), $total);

        return [
            'revenue_kobo' => $total,
            'confirmed' => ['revenue_kobo' => $c, 'profit_kobo' => (int) $r->c_profit, 'percent' => $cp],
            'estimated' => ['revenue_kobo' => $e, 'profit_kobo' => (int) $r->e_profit, 'percent' => $ep],
            'pending' => ['revenue_kobo' => $p, 'percent' => $total === 0 ? 0 : 100 - $cp - $ep],
        ];
    }
}

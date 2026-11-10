<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Counter speed for a business day (spec sections 7 and 14; check A1 and the pilot's speed threshold)
 * [S8, D47], from the counter's own timing of every sale: for each cashier, how many sales were timed,
 * the median time of a 3-item sale and how many took under 30 seconds, and the median time of a sale of
 * one scanned item and how many took under 8 seconds. A sale resumed from hold or finished after a slow
 * transfer is not timed fairly, so only sales finished where they started count.
 */
final class Speed
{
    public const THREE_ITEM_TARGET_MS = 30_000;

    public const ONE_SCAN_TARGET_MS = 8_000;

    /**
     * @return list<array{cashier: string, timed: int, median_ms: ?int, three_item: array{count: int, median_ms: ?int, under_target: int}, one_scan: array{count: int, median_ms: ?int, under_target: int}}>
     */
    public static function forDay(string $day): array
    {
        /** @var list<object{cashier: string, duration_ms: int|string, lines: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT u.name AS cashier, t.duration_ms, t.lines
            FROM sale_timings t JOIN sales s ON s.id = t.sale_id JOIN users u ON u.id = s.cashier_id
            WHERE s.kind = 'sale' AND s.business_day = ? AND t.duration_ms IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM parked_sale_events e WHERE e.type = 'completed' AND e.at = s.recorded_at)
            ORDER BY u.name
            SQL, [$day]);

        $by = [];
        foreach ($rows as $r) {
            /** @var list<string> $lines */
            $lines = json_decode($r->lines, true) ?: [];
            $ms = (int) $r->duration_ms;
            $by[$r->cashier]['all'][] = $ms;
            if (count($lines) === 3) {
                $by[$r->cashier]['three'][] = $ms;
            }
            if ($lines === ['scan']) {
                $by[$r->cashier]['scan'][] = $ms;
            }
        }

        $out = [];
        foreach ($by as $cashier => $times) {
            $three = $times['three'] ?? [];
            $scan = $times['scan'] ?? [];
            $out[] = [
                'cashier' => (string) $cashier,
                'timed' => count($times['all']),
                'median_ms' => self::median($times['all']),
                'three_item' => ['count' => count($three), 'median_ms' => self::median($three), 'under_target' => count(array_filter($three, fn (int $ms) => $ms < self::THREE_ITEM_TARGET_MS))],
                'one_scan' => ['count' => count($scan), 'median_ms' => self::median($scan), 'under_target' => count(array_filter($scan, fn (int $ms) => $ms < self::ONE_SCAN_TARGET_MS))],
            ];
        }

        return $out;
    }

    /**
     * The middle value, or the mean of the middle two; null for none.
     *
     * @param  list<int>  $values
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : intdiv($values[$n / 2 - 1] + $values[$n / 2], 2);
    }
}

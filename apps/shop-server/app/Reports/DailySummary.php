<?php

declare(strict_types=1);

namespace App\Reports;

use App\Shifts\Drawer;
use Illuminate\Support\Facades\DB;

/**
 * The daily summary (spec sections 10 and 12) [F14, D18; wireframe DailySummary]: the business day's sales by
 * payment method, the transfers still waiting, revenue in the three cost parts with their percentages, the
 * day's flag counts and the audit code (the latest root hash). On screen the owner also sees profit; the
 * printed slip carries no profit or cost amounts.
 */
final readonly class DailySummary
{
    /** The flags the slip always lists, even at nought (wireframe DailySummary). */
    public const SLIP_FLAGS = ['below_cost', 'below_floor', 'unusual_price', 'no_reference', 'discarded', 'key_open'];

    public function __construct(private Flags $flags, private Drawer $drawer) {}

    /**
     * @return array{day: string, opened_at: ?string, counters: list<string>, sales: array{count: int, total_kobo: int, by_method: list<array{method: string, sales: int, amount_kobo: int}>, voids: array{count: int, total_kobo: int}, returns: array{count: int, total_kobo: int}}, drawers: list<array{drawer: string, status: string, expected_cash_kobo: int, counted_cash_kobo: ?int, variance_kobo: ?int, flagged: bool, paid_out_kobo: int, drop_kobo: int, top_up_kobo: int}>, transfers_waiting: array{count: int, amount_kobo: int}, buckets: array<string, mixed>, flags: array<string, int>, root: ?array{hash: string, at: string}}
     */
    public function forDay(string $day, bool $withProfit): array
    {
        $opened = DB::table('shifts')->where('business_day', $day)->min('opened_at');
        /** @var list<string> $counters */
        $counters = DB::table('sales as s')->join('terminals as t', 't.id', '=', 's.terminal_id')->where('s.business_day', $day)
            ->distinct()->orderBy('t.name')->pluck('t.name')->all();
        /** @var object{n: int|string, amount: int|string} $waiting */
        $waiting = DB::selectOne("SELECT count(*) AS n, coalesce(sum(amount_kobo), 0) AS amount FROM parked_sales WHERE kind = 'awaiting_transfer' AND status = 'open'");
        $buckets = Profit::buckets($day, $day);
        if (! $withProfit) {
            unset($buckets['confirmed']['profit_kobo'], $buckets['estimated']['profit_kobo']);
        }
        /** @var object{hash: string, recorded_at: string}|null $root */
        $root = DB::selectOne('SELECT hash, recorded_at FROM audit_roots ORDER BY seq DESC LIMIT 1');

        return [
            'day' => $day,
            'opened_at' => is_string($opened) ? $opened : null,
            'counters' => $counters,
            'sales' => self::sales($day),
            'drawers' => $this->drawers($day),
            'transfers_waiting' => ['count' => (int) $waiting->n, 'amount_kobo' => (int) $waiting->amount],
            'buckets' => $buckets,
            'flags' => Flags::counts($this->flags->list(Period::day($day, $this->flags->zone()), false)),
            'root' => $root === null ? null : ['hash' => $root->hash, 'at' => $root->recorded_at],
        ];
    }

    /**
     * Each drawer's day: cash out and in beside sales, and its close (D68).
     *
     * @return list<array{drawer: string, status: string, expected_cash_kobo: int, counted_cash_kobo: ?int, variance_kobo: ?int, flagged: bool, paid_out_kobo: int, drop_kobo: int, top_up_kobo: int}>
     */
    private function drawers(string $day): array
    {
        $out = [];
        foreach (DB::table('shifts')->where('business_day', $day)->orderBy('opened_at')->pluck('id') as $id) {
            $f = $this->drawer->figures(is_string($id) ? $id : '');
            $out[] = ['drawer' => $f['drawer'], 'status' => $f['status'], 'expected_cash_kobo' => $f['expected_cash_kobo'], 'counted_cash_kobo' => $f['counted_cash_kobo'],
                'variance_kobo' => $f['variance_kobo'], 'flagged' => $f['flagged'], 'paid_out_kobo' => $f['paid_out_kobo'], 'drop_kobo' => $f['drop_kobo'], 'top_up_kobo' => $f['top_up_kobo']];
        }

        return $out;
    }

    /**
     * The business day's sales, in total and by payment method, after voids and returns: those are reversing
     * sales whose money is negative, so the totals net them and the counts leave them out (D68).
     *
     * @return array{count: int, total_kobo: int, by_method: list<array{method: string, sales: int, amount_kobo: int}>, voids: array{count: int, total_kobo: int}, returns: array{count: int, total_kobo: int}}
     */
    public static function sales(string $day): array
    {
        /** @var object{n: int|string, total: int|string, voids: int|string, void_total: int|string, returns: int|string, return_total: int|string} $sales */
        $sales = DB::selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE kind IN ('sale', 'back_entry')) AS n, coalesce(sum(total_kobo), 0) AS total,
                   count(*) FILTER (WHERE kind = 'void') AS voids, coalesce(sum(total_kobo) FILTER (WHERE kind = 'void'), 0) AS void_total,
                   count(*) FILTER (WHERE kind = 'return') AS returns, coalesce(sum(total_kobo) FILTER (WHERE kind = 'return'), 0) AS return_total
            FROM sales WHERE business_day = ?
            SQL, [$day]);
        /** @var list<object{method: string, n: int|string, amount: int|string}> $methods */
        $methods = DB::select(<<<'SQL'
            SELECT p.method, count(DISTINCT p.sale_id) FILTER (WHERE s.kind IN ('sale', 'back_entry')) AS n, sum(p.amount_kobo) AS amount
            FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.business_day = ?
            GROUP BY p.method
            SQL, [$day]);
        $byMethod = [];
        foreach (['cash', 'transfer', 'pos'] as $m) {
            $found = array_values(array_filter($methods, fn (object $r) => $r->method === $m))[0] ?? null;
            $byMethod[] = ['method' => $m, 'sales' => (int) ($found->n ?? 0), 'amount_kobo' => (int) ($found->amount ?? 0)];
        }

        return ['count' => (int) $sales->n, 'total_kobo' => (int) $sales->total, 'by_method' => $byMethod,
            'voids' => ['count' => (int) $sales->voids, 'total_kobo' => (int) $sales->void_total],
            'returns' => ['count' => (int) $sales->returns, 'total_kobo' => (int) $sales->return_total]];
    }
}

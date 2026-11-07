<?php

declare(strict_types=1);

namespace App\Reports;

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

    public function __construct(private Flags $flags) {}

    /**
     * @return array{day: string, opened_at: ?string, counters: list<string>, sales: array{count: int, total_kobo: int, by_method: list<array{method: string, sales: int, amount_kobo: int}>}, transfers_waiting: array{count: int, amount_kobo: int}, buckets: array<string, mixed>, flags: array<string, int>, root: ?array{hash: string, at: string}}
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
            'transfers_waiting' => ['count' => (int) $waiting->n, 'amount_kobo' => (int) $waiting->amount],
            'buckets' => $buckets,
            'flags' => Flags::counts($this->flags->list(Period::day($day, $this->flags->zone()), false)),
            'root' => $root === null ? null : ['hash' => $root->hash, 'at' => $root->recorded_at],
        ];
    }

    /**
     * The business day's sales, in total and by payment method.
     *
     * @return array{count: int, total_kobo: int, by_method: list<array{method: string, sales: int, amount_kobo: int}>}
     */
    public static function sales(string $day): array
    {
        /** @var object{n: int|string, total: int|string} $sales */
        $sales = DB::selectOne("SELECT count(*) AS n, coalesce(sum(total_kobo), 0) AS total FROM sales WHERE kind IN ('sale', 'back_entry') AND business_day = ?", [$day]);
        /** @var list<object{method: string, n: int|string, amount: int|string}> $methods */
        $methods = DB::select(<<<'SQL'
            SELECT p.method, count(DISTINCT p.sale_id) AS n, sum(p.amount_kobo) AS amount
            FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.kind IN ('sale', 'back_entry') AND s.business_day = ?
            GROUP BY p.method
            SQL, [$day]);
        $byMethod = [];
        foreach (['cash', 'transfer', 'pos'] as $m) {
            $found = array_values(array_filter($methods, fn (object $r) => $r->method === $m))[0] ?? null;
            $byMethod[] = ['method' => $m, 'sales' => (int) ($found->n ?? 0), 'amount_kobo' => (int) ($found->amount ?? 0)];
        }

        return ['count' => (int) $sales->n, 'total_kobo' => (int) $sales->total, 'by_method' => $byMethod];
    }
}

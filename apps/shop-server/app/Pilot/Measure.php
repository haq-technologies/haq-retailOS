<?php

declare(strict_types=1);

namespace App\Pilot;

use App\Reports\DailySummary;
use App\Reports\Dashboard;
use App\Reports\Speed;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The measured pilot's scorecard (Phase 5; spec section 14, Pilot success thresholds) [F14, S8, D56]. For
 * 14 trading days every counter runs beside the notebook, and the owner decides at gate G5. The five
 * measures, read live from the records:
 *
 * - Daily totals: on at least 10 of the 14 days the app's total per payment method matches the notebook,
 *   after each difference is traced and written down. A difference traced to the notebook (a sale never
 *   written down, a sum added wrongly) leaves the day matching; one traced to the app (a sale never rung,
 *   rung at the wrong price or under the wrong payment) does not.
 * - Receipts: every receipt number used once and gapless on every counter and epoch, every sale with a
 *   printed or pending receipt, and no lost sale still to re-enter.
 * - Speed: the day's typical (median) 3-item sale under 30 seconds on typical days, taken as 10 of every
 *   14 days that had timed 3-item sales, over at least 20 of them.
 * - Profit coverage: at least 80% of the 14 days' revenue has a cost, at sale or estimated, by the end of
 *   day 14, with the two parts shown, so backfilling alone cannot hide a poor result.
 * - The owner's decision: continue or stop, with the scorecard as it stood.
 *
 * A trading day is a business day with a sale in the app or a total in the notebook, so a day the app
 * missed altogether still counts against it.
 */
final readonly class Measure
{
    public const DAYS = 14;

    public const MATCH_DAYS = 10;

    public const METHODS = ['cash', 'transfer', 'pos'];

    public const SPEED_TARGET_MS = 30_000;

    public const SPEED_MIN_SALES = 20;

    public const COVERAGE_PERCENT = 80;

    /** On continue, the app becomes the record and the notebook stops a week later (PLAN.md, gate G5). */
    public const NOTEBOOK_STOPS_AFTER_DAYS = 7;

    public function __construct(private Dashboard $dashboard, private Settings $settings) {}

    public function zone(): string
    {
        $zone = $this->settings->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }

    /** Today's business day: the open shift's, or the shop's date. */
    public function today(): string
    {
        return $this->dashboard->today();
    }

    /**
     * The newest start of the 14 days, and how many times they were started again.
     *
     * @return array{id: string, first_day: string, at: string, by: string, note: ?string, restarts: int}|null
     */
    public function start(): ?array
    {
        $rows = DB::table('pilot_events as e')->join('users as u', 'u.id', '=', 'e.actor_id')->where('e.kind', 'started')
            ->orderByDesc('e.seq')->get(['e.id', 'e.first_day', 'e.at', 'u.name', 'e.note']);
        $newest = $rows->first();
        if ($newest === null) {
            return null;
        }

        /** @var object{id: string, first_day: string, at: string, name: string, note: ?string} $newest */
        return ['id' => $newest->id, 'first_day' => $newest->first_day, 'at' => $newest->at, 'by' => $newest->name, 'note' => $newest->note, 'restarts' => $rows->count() - 1];
    }

    /**
     * The owner's newest decision, with the scorecard as it stood.
     *
     * @return array{id: string, decision: string, note: ?string, at: string, by: string, notebook_stops: ?string, scorecard: array<string, mixed>, changes: int}|null
     */
    public function decision(): ?array
    {
        $rows = DB::table('pilot_events as e')->join('users as u', 'u.id', '=', 'e.actor_id')->where('e.kind', 'decided')
            ->orderByDesc('e.seq')->get(['e.id', 'e.decision', 'e.note', 'e.at', 'u.name', 'e.scorecard']);
        $newest = $rows->first();
        if ($newest === null) {
            return null;
        }
        /** @var object{id: string, decision: string, note: ?string, at: string, name: string, scorecard: string} $newest */
        $on = CarbonImmutable::parse($newest->at)->setTimezone($this->zone())->startOfDay();
        /** @var array<string, mixed> $scorecard */
        $scorecard = json_decode($newest->scorecard, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => $newest->id, 'decision' => $newest->decision, 'note' => $newest->note, 'at' => $newest->at, 'by' => $newest->name,
            'notebook_stops' => $newest->decision === 'continue' ? $on->addDays(self::NOTEBOOK_STOPS_AFTER_DAYS)->toDateString() : null,
            'scorecard' => $scorecard, 'changes' => $rows->count() - 1,
        ];
    }

    /**
     * The measured days: the first 14 trading days from the first day.
     *
     * @return list<string>
     */
    public function days(string $firstDay): array
    {
        /** @var list<object{d: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT d FROM (
                SELECT business_day AS d FROM sales WHERE kind IN ('sale', 'back_entry') AND business_day >= ?
                UNION
                SELECT n.business_day FROM notebook_totals n WHERE n.business_day >= ? AND n.amount_kobo > 0
                  AND n.seq = (SELECT max(x.seq) FROM notebook_totals x WHERE x.business_day = n.business_day AND x.method = n.method)
            ) trading ORDER BY d LIMIT ?
            SQL, [$firstDay, $firstDay, self::DAYS]);

        return array_map(fn (object $r) => (string) $r->d, $rows);
    }

    /**
     * One day: the app's and the notebook's totals per payment method, each difference and how much of it
     * is traced, the traces, and the day's verdict.
     *
     * @return array{day: string, app_kobo: int, notebook_kobo: ?int, entered: ?array{by: string, at: string}, methods: list<array{method: string, app_kobo: int, notebook_kobo: ?int, difference_kobo: ?int, traced_kobo: int, left_kobo: ?int}>, traces: list<array{id: string, method: string, amount_kobo: int, side: string, what: string, receipt_number: ?string, by: string, at: string}>, verdict: string}
     */
    public function day(string $day): array
    {
        $app = [];
        foreach (DailySummary::sales($day)['by_method'] as $m) {
            $app[$m['method']] = $m['amount_kobo'];
        }
        /** @var list<object{method: string, amount_kobo: int|string, name: string, entered_at: string}> $book */
        $book = DB::select(<<<'SQL'
            SELECT DISTINCT ON (n.method) n.method, n.amount_kobo, u.name, n.entered_at
            FROM notebook_totals n JOIN users u ON u.id = n.entered_by WHERE n.business_day = ?
            ORDER BY n.method, n.seq DESC
            SQL, [$day]);
        $notebook = [];
        $entered = null;
        foreach ($book as $b) {
            $notebook[$b->method] = (int) $b->amount_kobo;
            if ($entered === null || $b->entered_at > $entered['at']) {
                $entered = ['by' => $b->name, 'at' => $b->entered_at];
            }
        }
        $traces = $this->traces($day);

        $methods = [];
        $left = false;
        foreach (self::METHODS as $method) {
            $traced = array_sum(array_column(array_filter($traces, fn (array $t) => $t['method'] === $method), 'amount_kobo'));
            $nb = $notebook[$method] ?? null;
            $difference = $nb === null ? null : ($app[$method] ?? 0) - $nb;
            $methods[] = ['method' => $method, 'app_kobo' => $app[$method] ?? 0, 'notebook_kobo' => $nb, 'difference_kobo' => $difference,
                'traced_kobo' => $traced, 'left_kobo' => $difference === null ? null : $difference - $traced];
            $left = $left || ($difference !== null && $difference !== $traced);
        }
        $complete = count($notebook) === count(self::METHODS);

        return [
            'day' => $day,
            'app_kobo' => array_sum($app),
            'notebook_kobo' => $complete ? array_sum($notebook) : null,
            'entered' => $entered,
            'methods' => $methods,
            'traces' => $traces,
            'verdict' => self::verdict($complete, in_array('app', array_column($traces, 'side'), true), $left, $traces !== []),
        ];
    }

    /**
     * A day's verdict: no notebook totals yet; the app's record was wrong (it does not match); part of a
     * difference still to trace; matches once every difference is traced to the notebook; or matches.
     */
    public static function verdict(bool $entered, bool $appWrong, bool $left, bool $traced): string
    {
        return match (true) {
            ! $entered => 'no_notebook',
            $appWrong => 'app_wrong',
            $left => 'to_trace',
            $traced => 'matches_traced',
            default => 'matches',
        };
    }

    /**
     * The scorecard: the 14 days and the five measures, as they stand now.
     *
     * @return array<string, mixed>
     */
    public function scorecard(): array
    {
        $start = $this->start();
        $decision = $this->decision();
        $today = $this->today();
        if ($start === null) {
            return ['started' => false, 'start' => null, 'today' => $today, 'decision' => $decision];
        }

        $days = $this->days($start['first_day']);
        $last = $days === [] ? null : $days[count($days) - 1];
        $complete = count($days) === self::DAYS && $last !== null && $last < $today;
        $rows = [];
        foreach ($days as $i => $day) {
            $d = $this->day($day);
            $rows[] = ['n' => $i + 1, 'day' => $day, 'today' => $day === $today, 'app_kobo' => $d['app_kobo'], 'notebook_kobo' => $d['notebook_kobo'],
                'left_kobo' => array_sum(array_map(fn (array $m) => abs($m['left_kobo'] ?? 0), $d['methods'])), 'verdict' => $d['verdict']];
        }
        $measures = [
            'totals' => $this->totals($rows, $complete),
            'receipts' => $this->receipts($days, $complete),
            'speed' => $this->speed($days, $complete),
            'coverage' => $this->coverage($days, $complete),
        ];

        return [
            'started' => true,
            'start' => $start,
            'today' => $today,
            'measured' => count($days),
            'complete' => $complete,
            'days' => $rows,
            ...$measures,
            'passed' => count(array_filter($measures, fn (array $m) => $m['status'] === 'passed')),
            'decision' => $decision,
        ];
    }

    /**
     * The trading days whose totals still need work: no notebook totals yet, or a difference still to
     * trace. Today is left out, as it is still trading.
     */
    public function waiting(): int
    {
        $start = $this->start();
        if ($start === null || $this->decision() !== null) {
            return 0;
        }
        $today = $this->today();

        return count(array_filter($this->days($start['first_day']), fn (string $d) => $d < $today && in_array($this->day($d)['verdict'], ['no_notebook', 'to_trace'], true)));
    }

    /**
     * The traces of a day still in force: not withdrawals, and not withdrawn.
     *
     * @return list<array{id: string, method: string, amount_kobo: int, side: string, what: string, receipt_number: ?string, by: string, at: string}>
     */
    public function traces(string $day): array
    {
        /** @var list<object{id: string, method: string, amount_kobo: int|string, side: string, what: string, receipt_number: ?string, name: string, entered_at: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT t.id, t.method, t.amount_kobo, t.side, t.what, t.receipt_number, u.name, t.entered_at
            FROM pilot_traces t JOIN users u ON u.id = t.entered_by
            WHERE t.business_day = ? AND t.withdraws_id IS NULL AND NOT EXISTS (SELECT 1 FROM pilot_traces w WHERE w.withdraws_id = t.id)
            ORDER BY t.seq
            SQL, [$day]);

        return array_map(fn (object $r) => ['id' => $r->id, 'method' => $r->method, 'amount_kobo' => (int) $r->amount_kobo, 'side' => $r->side,
            'what' => $r->what, 'receipt_number' => $r->receipt_number, 'by' => $r->name, 'at' => $r->entered_at], $rows);
    }

    /**
     * Daily totals: passed at 10 matching days; failed once more than 4 days cannot match, or when the 14
     * are over and every day is entered and traced with fewer than 10 matching.
     *
     * @param  list<array{verdict: string}>  $rows
     * @return array{status: string, matching: int, not_matching: int, waiting: int, needed: int, of: int}
     */
    private function totals(array $rows, bool $complete): array
    {
        $count = fn (array $verdicts) => count(array_filter($rows, fn (array $r) => in_array($r['verdict'], $verdicts, true)));
        $matching = $count(['matches', 'matches_traced']);
        $failed = $count(['app_wrong']);
        $waiting = count($rows) - $matching - $failed;

        return [
            'status' => match (true) {
                $matching >= self::MATCH_DAYS => 'passed',
                $failed > self::DAYS - self::MATCH_DAYS, $complete && $waiting === 0 => 'failed',
                default => 'open',
            },
            'matching' => $matching, 'not_matching' => $failed, 'waiting' => $waiting, 'needed' => self::MATCH_DAYS, 'of' => self::DAYS,
        ];
    }

    /**
     * Receipts on the measured days: every number used once and gapless on each counter and epoch with a
     * sale those days, every sale with a receipt printed or pending, and no lost sale left to re-enter.
     * A sale re-entered from a counter's journal after a restore was recovered, not lost.
     *
     * @param  list<string>  $days
     * @return array{status: string, sales: int, duplicates: int, gaps: list<array{counter: string, epoch: int, missing: int, low: int, high: int}>, no_receipt: int, pending_print: list<string>, lost_waiting: int, recovered: int}
     */
    private function receipts(array $days, bool $complete): array
    {
        $out = ['status' => 'open', 'sales' => 0, 'duplicates' => 0, 'gaps' => [], 'no_receipt' => 0, 'pending_print' => [], 'lost_waiting' => 0, 'recovered' => 0];
        if ($days === []) {
            return $out;
        }
        $in = implode(', ', array_fill(0, count($days), '?'));

        $out['sales'] = DB::table('sales')->whereIn('kind', ['sale', 'back_entry'])->whereIn('business_day', $days)->count();
        /** @var object{n: int|string} $dup */
        $dup = DB::selectOne('SELECT count(*) AS n FROM (SELECT 1 FROM sales GROUP BY branch_id, terminal_id, epoch, receipt_no HAVING count(*) > 1) d');
        $out['duplicates'] = (int) $dup->n;

        // Each numbering run with a sale on a measured day is checked whole, as the rehearsal check does.
        /** @var list<object{code: string, epoch: int|string, n: int|string, low: int|string, high: int|string, reason: string}> $runs */
        $runs = DB::select(<<<SQL
            SELECT t.code, s.epoch, count(*) AS n, min(s.receipt_no) AS low, max(s.receipt_no) AS high, e.reason
            FROM sales s JOIN terminals t ON t.id = s.terminal_id JOIN epochs e ON e.terminal_id = s.terminal_id AND e.epoch = s.epoch
            WHERE (s.terminal_id, s.epoch) IN (SELECT terminal_id, epoch FROM sales WHERE business_day IN ({$in}))
            GROUP BY t.code, s.epoch, e.reason ORDER BY t.code, s.epoch
            SQL, $days);
        foreach ($runs as $r) {
            $first = in_array($r->reason, ['restore', 'replacement', 'reinstall'], true) ? 1001 : 1;
            $missing = (int) $r->high - $first + 1 - (int) $r->n;
            if ($missing > 0 || (int) $r->low !== $first) {
                $out['gaps'][] = ['counter' => $r->code, 'epoch' => (int) $r->epoch, 'missing' => max($missing, 0), 'low' => (int) $r->low, 'high' => (int) $r->high];
            }
        }

        // A back entry prints nothing: the customer already holds the receipt (D44).
        $out['no_receipt'] = DB::table('sales as s')->where('s.kind', 'sale')->whereIn('s.business_day', $days)
            ->whereNotExists(fn ($q) => $q->from('print_jobs as j')->whereColumn('j.sale_id', 's.id')->where('j.kind', 'receipt'))->count();
        /** @var list<string> $pending */
        $pending = DB::table('print_jobs as j')->join('sales as s', 's.id', '=', 'j.sale_id')->where('j.kind', 'receipt')
            ->whereIn('j.status', ['pending', 'failed'])->whereIn('s.business_day', $days)->orderBy('s.receipt_number')->pluck('s.receipt_number')->all();
        $out['pending_print'] = $pending;
        $out['lost_waiting'] = DB::table('lost_sales as l')->whereIn('l.business_day', $days)
            ->whereNotExists(fn ($q) => $q->from('back_entries as b')->whereColumn('b.lost_sale_id', 'l.id'))->count();
        $out['recovered'] = DB::table('sales')->where('kind', 'back_entry')->whereIn('business_day', $days)->count();

        $problem = $out['duplicates'] > 0 || $out['gaps'] !== [] || $out['no_receipt'] > 0 || $out['lost_waiting'] > 0;
        $out['status'] = $problem ? 'failed' : ($complete ? 'passed' : 'open');

        return $out;
    }

    /**
     * Speed from the counter's own timing (check A1) [S8, D47]: each day's typical 3-item sale, and each
     * cashier's over the 14 days. Sales resumed from hold are left out, as their time is not the cashier's.
     *
     * @param  list<string>  $days
     * @return array{status: string, timed: int, days_with: int, days_under: int, days: list<array{day: string, count: int, median_ms: ?int, under_target: int}>, cashiers: list<array{cashier: string, count: int, median_ms: ?int, under_target: int}>}
     */
    private function speed(array $days, bool $complete): array
    {
        $byDay = [];
        $byCashier = [];
        if ($days !== []) {
            $in = implode(', ', array_fill(0, count($days), '?'));
            /** @var list<object{business_day: string, cashier: string, duration_ms: int|string, lines: string}> $rows */
            $rows = DB::select(<<<SQL
                SELECT s.business_day, u.name AS cashier, t.duration_ms, t.lines
                FROM sale_timings t JOIN sales s ON s.id = t.sale_id JOIN users u ON u.id = s.cashier_id
                WHERE s.kind = 'sale' AND s.business_day IN ({$in}) AND t.duration_ms IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM parked_sale_events e WHERE e.type = 'completed' AND e.at = s.recorded_at)
                ORDER BY u.name
                SQL, $days);
            foreach ($rows as $r) {
                $lines = json_decode($r->lines, true);
                if (is_array($lines) && count($lines) === 3) {
                    $byDay[$r->business_day][] = (int) $r->duration_ms;
                    $byCashier[$r->cashier][] = (int) $r->duration_ms;
                }
            }
        }
        $summary = fn (array $times) => ['count' => count($times), 'median_ms' => Speed::median(array_values($times)),
            'under_target' => count(array_filter($times, fn (int $ms) => $ms < self::SPEED_TARGET_MS))];

        $dayRows = [];
        foreach ($days as $day) {
            $dayRows[] = ['day' => $day, ...$summary($byDay[$day] ?? [])];
        }
        $with = array_values(array_filter($dayRows, fn (array $d) => $d['count'] > 0));
        $under = count(array_filter($with, fn (array $d) => $d['median_ms'] !== null && $d['median_ms'] < self::SPEED_TARGET_MS));
        $timed = array_sum(array_column($dayRows, 'count'));
        $ok = $timed >= self::SPEED_MIN_SALES && $with !== [] && $under * self::DAYS >= count($with) * self::MATCH_DAYS;
        $cashiers = [];
        foreach ($byCashier as $name => $times) {
            $cashiers[] = ['cashier' => (string) $name, ...$summary($times)];
        }

        return ['status' => $complete ? ($ok ? 'passed' : 'failed') : 'open', 'timed' => $timed, 'days_with' => count($with), 'days_under' => $under,
            'days' => $dayRows, 'cashiers' => $cashiers];
    }

    /**
     * Profit coverage: the share of the measured days' revenue with a cost, at sale or estimated, counted
     * at the end of day 14 (midnight after it, or its last sale if later). Until then, as it stands now.
     * Percentages only, never cost amounts.
     *
     * @param  list<string>  $days
     * @return array{status: string, counted_at: ?string, percent: int, confirmed_percent: int, estimated_percent: int, pending_percent: int, now_percent: ?int}
     */
    private function coverage(array $days, bool $complete): array
    {
        $cutoff = null;
        if ($complete) {
            $last = $days[count($days) - 1];
            $midnight = CarbonImmutable::parse($last, $this->zone())->addDay()->startOfDay();
            $latest = DB::table('sales')->where('business_day', $last)->max('recorded_at');
            $cutoff = is_string($latest) && CarbonImmutable::parse($latest)->greaterThan($midnight) ? CarbonImmutable::parse($latest) : $midnight;
        }
        $at = $this->coverageAt($days, $cutoff);
        $total = $at['confirmed'] + $at['estimated'] + $at['pending'];
        $percent = fn (int $part) => $total === 0 ? 0 : intdiv($part * 100, $total);
        $ok = $total > 0 && ($at['confirmed'] + $at['estimated']) * 100 >= $total * self::COVERAGE_PERCENT;
        $now = null;
        if ($cutoff !== null) {
            $n = $this->coverageAt($days, null);
            $nt = $n['confirmed'] + $n['estimated'] + $n['pending'];
            $now = $nt === 0 ? 0 : intdiv(($n['confirmed'] + $n['estimated']) * 100, $nt);
        }

        return [
            'status' => $complete && $total > 0 ? ($ok ? 'passed' : 'failed') : 'open',
            'counted_at' => $cutoff?->toIso8601String(),
            'percent' => $percent($at['confirmed'] + $at['estimated']),
            'confirmed_percent' => $percent($at['confirmed']),
            'estimated_percent' => $percent($at['estimated']),
            'pending_percent' => $percent($at['pending']),
            'now_percent' => $now,
        ];
    }

    /**
     * Revenue of the days by cost part, with estimates as they stood at a moment (the newest estimate
     * entered by then counts, including an undo that took one back), or now.
     *
     * @param  list<string>  $days
     * @return array{confirmed: int, estimated: int, pending: int}
     */
    private function coverageAt(array $days, ?CarbonImmutable $at): array
    {
        if ($days === []) {
            return ['confirmed' => 0, 'estimated' => 0, 'pending' => 0];
        }
        $in = implode(', ', array_fill(0, count($days), '?'));
        $by = $at === null ? '' : 'AND r.created_at <= ?';
        $bindings = $at === null ? $days : [$at->format('Y-m-d H:i:s.uP'), ...$days];
        /** @var object{c: int|string, e: int|string, p: int|string} $r */
        $r = DB::selectOne(<<<SQL
            WITH lines AS (
                SELECT l.line_total_kobo AS revenue, l.cost_snapshot_kobo AS snap,
                       (SELECT r.unit_cost_kobo FROM cost_resolutions r WHERE r.sale_line_id = l.id {$by} ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS est
                FROM sale_lines l JOIN sales s ON s.id = l.sale_id
                WHERE s.kind IN ('sale', 'back_entry') AND s.business_day IN ({$in})
            )
            SELECT coalesce(sum(revenue) FILTER (WHERE snap IS NOT NULL), 0) AS c,
                   coalesce(sum(revenue) FILTER (WHERE snap IS NULL AND est IS NOT NULL), 0) AS e,
                   coalesce(sum(revenue) FILTER (WHERE snap IS NULL AND est IS NULL), 0) AS p
            FROM lines
            SQL, $bindings);

        return ['confirmed' => (int) $r->c, 'estimated' => (int) $r->e, 'pending' => (int) $r->p];
    }
}

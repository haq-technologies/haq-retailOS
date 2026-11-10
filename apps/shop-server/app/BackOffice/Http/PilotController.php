<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\BackOffice\Access;
use App\Pilot\Measure;
use App\Pilot\PilotRefused;
use App\Pilot\Records;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The measured pilot (Phase 5; spec section 14, Pilot success thresholds) [D56]: the scorecard, each day's
 * totals beside the notebook with the day's sales for tracing, the notebook's totals and the traces, and
 * the owner starting the 14 days and recording the decision at gate G5. Totals and percentages only:
 * no cost reaches anyone here.
 */
final class PilotController
{
    use ActsForUser;

    public function index(Request $request, Measure $measure, Access $access): JsonResponse
    {
        $actor = $this->allowed($request, 'pilot');

        return response()->json([...$measure->scorecard(), 'can_decide' => $access->allows($actor, 'pilot_decide'), 'can_export' => $access->allows($actor, 'export'),
            'rules' => ['days' => Measure::DAYS, 'match_days' => Measure::MATCH_DAYS, 'speed_target_ms' => Measure::SPEED_TARGET_MS,
                'speed_min_sales' => Measure::SPEED_MIN_SALES, 'coverage_percent' => Measure::COVERAGE_PERCENT, 'notebook_stops_after_days' => Measure::NOTEBOOK_STOPS_AFTER_DAYS]]);
    }

    /** One day beside the notebook, with every sale that day to trace differences against. */
    public function day(Request $request, Measure $measure, string $day): JsonResponse
    {
        $this->allowed($request, 'pilot');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1, 404);
        $start = $measure->start();
        $days = $start === null ? [] : $measure->days($start['first_day']);
        $n = array_search($day, $days, true);

        /** @var list<object{id: string, receipt_number: string, recorded_at: string, kind: string, total_kobo: int|string, counter: string, cashier: string, cash: int|string, transfer: int|string, pos: int|string}> $sales */
        $sales = DB::select(<<<'SQL'
            SELECT s.id, s.receipt_number, s.recorded_at, s.kind, s.total_kobo, t.code AS counter, u.name AS cashier,
                   coalesce(sum(p.amount_kobo) FILTER (WHERE p.method = 'cash'), 0) AS cash,
                   coalesce(sum(p.amount_kobo) FILTER (WHERE p.method = 'transfer'), 0) AS transfer,
                   coalesce(sum(p.amount_kobo) FILTER (WHERE p.method = 'pos'), 0) AS pos
            FROM sales s JOIN terminals t ON t.id = s.terminal_id JOIN users u ON u.id = s.cashier_id
            LEFT JOIN payments p ON p.sale_id = s.id
            WHERE s.business_day = ? AND s.kind IN ('sale', 'back_entry')
            GROUP BY s.id, t.code, u.name ORDER BY s.recorded_at, s.receipt_number LIMIT 2000
            SQL, [$day]);

        return response()->json([
            ...$measure->day($day),
            'n' => $n === false ? null : $n + 1,
            'today' => $day === $measure->today(),
            'open' => $start !== null && $measure->decision() === null && $day >= $start['first_day'] && $day <= $measure->today(),
            'sales' => array_map(fn (object $s) => ['id' => $s->id, 'receipt_number' => $s->receipt_number, 'at' => $s->recorded_at, 'back_entry' => $s->kind === 'back_entry',
                'total_kobo' => (int) $s->total_kobo, 'counter' => $s->counter, 'cashier' => $s->cashier,
                'cash_kobo' => (int) $s->cash, 'transfer_kobo' => (int) $s->transfer, 'pos_kobo' => (int) $s->pos], $sales),
        ]);
    }

    public function start(Request $request, Records $records): JsonResponse
    {
        $actor = $this->allowed($request, 'pilot_decide');
        $data = $request->validate(['first_day' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => ['id' => $records->start($actor, (string) $data['first_day'], isset($data['note']) ? (string) $data['note'] : null)]);
    }

    public function notebook(Request $request, Records $records): JsonResponse
    {
        $actor = $this->allowed($request, 'pilot');
        $data = $request->validate(['day' => ['required', 'string'], 'cash_kobo' => ['required', 'integer'], 'transfer_kobo' => ['required', 'integer'], 'pos_kobo' => ['required', 'integer']]);

        return $this->attempt(function () use ($records, $actor, $data): array {
            $records->notebook($actor, (string) $data['day'], ['cash' => (int) $data['cash_kobo'], 'transfer' => (int) $data['transfer_kobo'], 'pos' => (int) $data['pos_kobo']]);

            return ['ok' => true];
        });
    }

    public function trace(Request $request, Records $records): JsonResponse
    {
        $actor = $this->allowed($request, 'pilot');
        $data = $request->validate([
            'day' => ['required', 'string'], 'method' => ['required', 'string'], 'amount_kobo' => ['required', 'integer'],
            'side' => ['required', 'string'], 'what' => ['required', 'string', 'max:200'], 'receipt_number' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->attempt(fn () => ['id' => $records->trace($actor, (string) $data['day'], (string) $data['method'], (int) $data['amount_kobo'],
            (string) $data['side'], (string) $data['what'], isset($data['receipt_number']) ? (string) $data['receipt_number'] : null)]);
    }

    public function withdraw(Request $request, Records $records, string $trace): JsonResponse
    {
        $actor = $this->allowed($request, 'pilot');

        return $this->attempt(function () use ($records, $actor, $trace): array {
            $records->withdraw($actor, $trace);

            return ['ok' => true];
        });
    }

    public function decide(Request $request, Records $records): JsonResponse
    {
        $actor = $this->allowed($request, 'pilot_decide');
        $data = $request->validate(['decision' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => ['id' => $records->decide($actor, (string) $data['decision'], isset($data['note']) ? (string) $data['note'] : null)]);
    }

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    private function attempt(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (PilotRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

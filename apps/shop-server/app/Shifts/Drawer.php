<?php

declare(strict_types=1);

namespace App\Shifts;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\ApprovalAction;
use App\Identity\ApprovalRefused;
use App\Identity\Approvals;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The drawer's cash beyond sales, and closing the day (spec section 9, Shifts and cash reconciliation and
 * Paid-outs, drops and top-ups) [F19, S12; D68].
 *
 * - A paid-out (a small expense from the till), a drop to the safe or a float top-up each needs a reason.
 *   Above the shop's limit a cashier needs a manager's PIN; a manager or the owner records their own.
 * - Expected cash = the opening float, plus the cash taken (cash refunds are negative, so they come off), plus
 *   top-ups, less paid-outs and drops.
 * - Closing the day is a blind count: the holder types what is in the drawer before seeing what was expected.
 *   The variance is recorded; above the shop's threshold it is flagged for the owner. A closed shift never
 *   changes (the mutability matrix), and the next sale opens a new one.
 *
 * @phpstan-type Figures array{shift_id: string, drawer: string, business_day: string, opened_at: string, closed_at: ?string, status: string, closed_by: ?string, opening_float_kobo: int, sales: array{count: int, total_kobo: int}, by_method: array{cash: int, transfer: int, pos: int}, voids: array{count: int, total_kobo: int}, returns: array{count: int, total_kobo: int}, paid_out_kobo: int, drop_kobo: int, top_up_kobo: int, expected_cash_kobo: int, counted_cash_kobo: ?int, variance_kobo: ?int, flagged: bool}
 */
final readonly class Drawer
{
    public const TYPES = ['paid_out' => ApprovalAction::PaidOut, 'drop' => ApprovalAction::CashDrop, 'top_up' => ApprovalAction::CashTopUp];

    public function __construct(
        private Shifts $shifts,
        private Approvals $approvals,
        private Settings $settings,
        private AuditLog $audit,
    ) {}

    /**
     * Records a paid-out, a drop or a top-up. A retry with the same request ID returns the first record.
     *
     * @return array{id: string, type: string, amount_kobo: int, already: bool}
     */
    public function record(Terminal $terminal, User $actor, string $type, int $amountKobo, string $reason, string $requestId, ?string $approvalToken): array
    {
        $action = self::TYPES[$type] ?? throw new DrawerRefused('Choose a paid-out, a drop to the safe or a top-up.');
        $reason = trim($reason);
        if ($amountKobo <= 0 || $amountKobo > 10_000_000_000) {
            throw new DrawerRefused('Type the amount.');
        }
        if ($reason === '') {
            throw new DrawerRefused('Say what the cash is for.');
        }
        if (! $terminal->canSell()) {
            throw new DrawerRefused('This device has no drawer.');
        }
        $already = DB::table('cash_events')->where('request_id', $requestId)->first(['id', 'type', 'amount_kobo']);
        if ($already !== null) {
            return ['id' => (string) $already->id, 'type' => (string) $already->type, 'amount_kobo' => (int) $already->amount_kobo, 'already' => true];
        }
        $limit = $this->settings->get('cash.approval_limit_kobo');
        $needsManager = ! $actor->role->canApprove() && $amountKobo > (is_int($limit) ? $limit : 0);

        try {
            return DB::transaction(function () use ($terminal, $actor, $type, $action, $amountKobo, $reason, $requestId, $approvalToken, $needsManager): array {
                $shift = $this->shifts->openFor($terminal, $actor->id);
                $approvalId = null;
                $approver = null;
                if ($needsManager) {
                    if ($approvalToken === null) {
                        throw new NeedsApproval('Above the shop\'s limit a manager must approve this.', $action, $requestId, $amountKobo);
                    }
                    try {
                        $approvalId = $this->approvals->bind($terminal, $approvalToken, $action, $requestId, null, $amountKobo);
                    } catch (ApprovalRefused $e) {
                        throw new NeedsApproval($e->getMessage(), $action, $requestId, $amountKobo);
                    }
                    $approver = DB::table('approvals')->where('id', $approvalId)->value('approver_id');
                }
                $id = Ulid::generate();
                DB::table('cash_events')->insert([
                    'id' => $id, 'shift_id' => $shift->id, 'drawer_id' => $shift->drawer_id, 'type' => $type, 'amount_kobo' => $amountKobo,
                    'reason' => mb_substr($reason, 0, 200), 'actor_id' => $actor->id, 'approved_by' => is_string($approver) ? $approver : null,
                    'approval_id' => $approvalId, 'request_id' => $requestId, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
                ]);
                $this->audit->record(new AuditEntry(action: "cash.{$type}", actorId: $actor->id, terminalId: $terminal->id, subjectType: 'shift', subjectId: $shift->id,
                    after: ['amount_kobo' => $amountKobo, 'reason' => $reason, 'approved_by' => $approver]));

                return ['id' => $id, 'type' => $type, 'amount_kobo' => $amountKobo, 'already' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same request from two taps at once: the first one counts.
            $first = DB::table('cash_events')->where('request_id', $requestId)->first(['id', 'type', 'amount_kobo']);
            if ($first === null) {
                throw $e;
            }

            return ['id' => (string) $first->id, 'type' => (string) $first->type, 'amount_kobo' => (int) $first->amount_kobo, 'already' => true];
        }
    }

    /**
     * Closes the counter's open shift with a blind count.
     *
     * @return Figures
     */
    public function close(Terminal $terminal, User $actor, int $countedKobo): array
    {
        if ($countedKobo < 0 || $countedKobo > 10_000_000_000) {
            throw new DrawerRefused('Type the cash counted in the drawer.');
        }

        return DB::transaction(function () use ($terminal, $actor, $countedKobo): array {
            $drawerId = $this->shifts->drawerFor($terminal);
            // The lock that opens a drawer's shift, so a sale and the close never cross.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['shift:'.$drawerId]);
            $shiftId = DB::table('shifts')->where('drawer_id', $drawerId)->where('status', 'open')->value('id');
            if (! is_string($shiftId)) {
                throw new DrawerRefused('There is no open shift on this counter to close.');
            }
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            $figures = $this->figures($shiftId);
            $variance = $countedKobo - $figures['expected_cash_kobo'];
            DB::table('shift_events')->insert([
                'id' => Ulid::generate(), 'shift_id' => $shiftId, 'type' => 'close_count', 'cashier_id' => $actor->id,
                'counted_cash_kobo' => $countedKobo, 'expected_cash_kobo' => $figures['expected_cash_kobo'], 'variance_kobo' => $variance, 'at' => $now,
            ]);
            DB::table('shifts')->where('id', $shiftId)->update(['status' => 'closed', 'closed_at' => $now]);
            $this->audit->record(new AuditEntry(action: 'shift.closed', actorId: $actor->id, terminalId: $terminal->id, subjectType: 'shift', subjectId: $shiftId,
                after: ['business_day' => $figures['business_day'], 'counted_kobo' => $countedKobo, 'expected_kobo' => $figures['expected_cash_kobo'], 'variance_kobo' => $variance]));

            return $this->figures($shiftId);
        });
    }

    /**
     * Everything a shift's Z-report shows. Before the close, counted and variance are null.
     *
     * @return Figures
     */
    public function figures(string $shiftId): array
    {
        /** @var object{id: string, label: string, business_day: string, opened_at: string, closed_at: ?string, status: string, opening_float_kobo: ?int} $shift */
        $shift = DB::table('shifts as sh')->join('drawers as d', 'd.id', '=', 'sh.drawer_id')->where('sh.id', $shiftId)
            ->first(['sh.id', 'd.label', 'sh.business_day', 'sh.opened_at', 'sh.closed_at', 'sh.status', 'sh.opening_float_kobo']);
        /** @var list<object{kind: string, n: int|string, total: int|string}> $kinds */
        $kinds = DB::select('SELECT kind, count(*) AS n, coalesce(sum(total_kobo), 0) AS total FROM sales WHERE shift_id = ? GROUP BY kind', [$shiftId]);
        $byKind = [];
        foreach ($kinds as $k) {
            $byKind[$k->kind] = ['count' => (int) $k->n, 'total_kobo' => (int) $k->total];
        }
        $methods = DB::table('payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.shift_id', $shiftId)
            ->groupBy('p.method')->selectRaw('p.method, coalesce(sum(p.amount_kobo), 0) AS total')->pluck('total', 'method')->all();
        $cash = DB::table('cash_events')->where('shift_id', $shiftId)->whereIn('type', array_keys(self::TYPES))
            ->groupBy('type')->selectRaw('type, coalesce(sum(amount_kobo), 0) AS total')->pluck('total', 'type')->all();
        $int = fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        $float = $int($shift->opening_float_kobo);
        $expected = $float + $int($methods['cash'] ?? 0) + $int($cash['top_up'] ?? 0) - $int($cash['paid_out'] ?? 0) - $int($cash['drop'] ?? 0);
        /** @var object{counted_cash_kobo: int, variance_kobo: int, name: ?string}|null $count */
        $count = DB::table('shift_events as e')->leftJoin('users as u', 'u.id', '=', 'e.cashier_id')->where('e.shift_id', $shiftId)
            ->where('e.type', 'close_count')->orderByDesc('e.at')->first(['e.counted_cash_kobo', 'e.variance_kobo', 'u.name']);
        $threshold = $this->settings->get('cash.variance_flag_kobo');
        $sales = ['count' => ($byKind['sale']['count'] ?? 0) + ($byKind['back_entry']['count'] ?? 0),
            'total_kobo' => ($byKind['sale']['total_kobo'] ?? 0) + ($byKind['back_entry']['total_kobo'] ?? 0)];

        return [
            'shift_id' => $shift->id, 'drawer' => $shift->label, 'business_day' => $shift->business_day,
            'opened_at' => $shift->opened_at, 'closed_at' => $shift->closed_at, 'status' => $shift->status,
            'closed_by' => $count?->name, 'opening_float_kobo' => $float, 'sales' => $sales,
            'by_method' => ['cash' => $int($methods['cash'] ?? 0), 'transfer' => $int($methods['transfer'] ?? 0), 'pos' => $int($methods['pos'] ?? 0)],
            'voids' => $byKind['void'] ?? ['count' => 0, 'total_kobo' => 0],
            'returns' => $byKind['return'] ?? ['count' => 0, 'total_kobo' => 0],
            'paid_out_kobo' => $int($cash['paid_out'] ?? 0), 'drop_kobo' => $int($cash['drop'] ?? 0), 'top_up_kobo' => $int($cash['top_up'] ?? 0),
            'expected_cash_kobo' => $expected,
            'counted_cash_kobo' => $count === null ? null : (int) $count->counted_cash_kobo,
            'variance_kobo' => $count === null ? null : (int) $count->variance_kobo,
            'flagged' => $count !== null && abs((int) $count->variance_kobo) > (is_int($threshold) ? $threshold : 0),
        ];
    }

    /** The counter's last closed shift, for printing its Z-report again. */
    public function lastClosed(Terminal $terminal): ?string
    {
        $id = DB::table('shifts')->where('drawer_id', $this->shifts->drawerFor($terminal))->where('status', 'closed')->orderByDesc('closed_at')->value('id');

        return is_string($id) ? $id : null;
    }
}

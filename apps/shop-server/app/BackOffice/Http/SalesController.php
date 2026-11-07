<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Cost\CostAccess;
use App\Platform\Ulid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One sale as the back office shows it, behind every "View sale" (wireframes FlagList and BackDashboard):
 * the receipt, its lines with how each price was checked, and the payments. Cost and profit only for
 * those who may see cost [F10].
 */
final class SalesController
{
    use ActsForUser;

    public function show(Request $request, string $sale, CostAccess $cost): JsonResponse
    {
        $actor = $this->allowed($request, 'dashboard');
        abort_unless(Ulid::isValid($sale), 404);
        /** @var object{id: string, receipt_number: string, kind: string, total_kobo: int, business_day: string, recorded_at: string, customer_type: string, clock_state: string, cashier: string, terminal: string}|null $row */
        $row = DB::table('sales as s')->join('users as u', 'u.id', '=', 's.cashier_id')->join('terminals as t', 't.id', '=', 's.terminal_id')
            ->where('s.id', $sale)->first(['s.id', 's.receipt_number', 's.kind', 's.total_kobo', 's.business_day', 's.recorded_at', 's.customer_type', 's.clock_state', 'u.name as cashier', 't.name as terminal']);
        abort_if($row === null, 404);
        $withCost = $cost->canSee($actor);

        /** @var list<object{id: string, name_snapshot: string, colour_note: ?string, qty: int, unit_price_kobo: int, line_total_kobo: int, cost_snapshot_kobo: ?int, price_source: string, flags: string, approver: ?string, estimate: ?int}> $lines */
        $lines = DB::table('sale_lines as l')->leftJoin('approvals as a', 'a.id', '=', 'l.approval_id')->leftJoin('users as m', 'm.id', '=', 'a.approver_id')
            ->where('l.sale_id', $sale)->orderBy('l.line_no')
            ->get(['l.id', 'l.name_snapshot', 'l.colour_note', 'l.qty', 'l.unit_price_kobo', 'l.line_total_kobo', 'l.cost_snapshot_kobo', 'l.price_source', 'l.flags', 'm.name as approver',
                DB::raw('(SELECT r.unit_cost_kobo FROM cost_resolutions r WHERE r.sale_line_id = l.id ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS estimate')])->all();
        /** @var list<object{method: string, amount_kobo: int, tendered_kobo: ?int, change_kobo: ?int, reference: ?string, alert_seen: ?bool}> $payments */
        $payments = DB::table('payments')->where('sale_id', $sale)->orderBy('id')->get(['method', 'amount_kobo', 'tendered_kobo', 'change_kobo', 'reference', 'alert_seen'])->all();

        return response()->json([
            'id' => $row->id, 'receipt_number' => $row->receipt_number, 'kind' => $row->kind, 'total_kobo' => (int) $row->total_kobo,
            'business_day' => $row->business_day, 'recorded_at' => $row->recorded_at, 'customer_type' => $row->customer_type,
            'clock_state' => $row->clock_state, 'cashier' => $row->cashier, 'terminal' => $row->terminal,
            'lines' => array_map(function (object $l) use ($withCost): array {
                /** @var array{outside?: string, confirmed?: bool, retyped?: bool, below_floor?: bool, first_sale?: bool, range?: list<int|string>} $flags */
                $flags = json_decode($l->flags, true) ?: [];
                $line = [
                    'name' => $l->name_snapshot, 'colour' => $l->colour_note, 'qty' => (int) $l->qty, 'unit_price_kobo' => (int) $l->unit_price_kobo,
                    'line_total_kobo' => (int) $l->line_total_kobo, 'price_source' => $l->price_source, 'approver' => $l->approver,
                    'check' => match (true) {
                        ($flags['below_floor'] ?? false) === true => 'Below the floor, approved',
                        ($flags['retyped'] ?? false) === true => 'Very high price, retyped',
                        ($flags['confirmed'] ?? false) === true => 'Outside the usual range, confirmed',
                        ($flags['outside'] ?? null) === 'below' && $l->approver !== null => 'Below the usual range, approved',
                        ($flags['first_sale'] ?? false) === true => 'First sale, nothing to compare',
                        default => null,
                    },
                    'usual' => isset($flags['range'][0], $flags['range'][1]) ? ['low' => (int) $flags['range'][0], 'high' => (int) $flags['range'][1]] : null,
                ];
                if ($withCost) {
                    $known = $l->cost_snapshot_kobo ?? $l->estimate;
                    $line['cost_kobo'] = $known === null ? null : (int) $known;
                    $line['cost_bucket'] = $l->cost_snapshot_kobo !== null ? 'confirmed' : ($l->estimate !== null ? 'estimated' : 'pending');
                }

                return $line;
            }, $lines),
            'payments' => array_map(fn (object $p) => ['method' => $p->method, 'amount_kobo' => (int) $p->amount_kobo, 'tendered_kobo' => $p->tendered_kobo === null ? null : (int) $p->tendered_kobo,
                'change_kobo' => $p->change_kobo === null ? null : (int) $p->change_kobo, 'reference' => $p->reference, 'alert_seen' => $p->alert_seen], $payments),
        ]);
    }
}

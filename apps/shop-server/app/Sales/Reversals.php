<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Hasher;
use App\Cost\Costs;
use App\Identity\ApprovalAction;
use App\Identity\ApprovalRefused;
use App\Identity\Approvals;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Platform\Clock;
use App\Platform\Ulid;
use App\Sales\Events\SaleFinished;
use App\Settings\Settings;
use App\Shifts\Shifts;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Voids and returns (spec section 9) [F7, F19; D68]. Each is a sale of its own kind with its own receipt
 * number, whose lines and payments reverse what they undo, so the sale itself is never edited and every total
 * nets it by itself.
 *
 * - A void undoes a whole sale. A manager does it (a cashier asks for a manager's PIN), with a reason, within
 *   the sale's shift on its drawer, and never once the sale has a return. Anything later is a return.
 * - A return undoes some lines, found by the receipt number. It needs a manager's PIN, a reason and a
 *   disposition, and refunds by cash, transfer or POS. Each line is locked while it is checked, so no line is
 *   returned beyond what was sold, even from two counters at once. With no receipt, only the owner returns.
 * - Both are idempotent: the counter's request ID is the sale's idempotency key, so a retry gives the first.
 */
final readonly class Reversals
{
    public const REASONS = ['wrong_item' => 'Wrong item', 'defective' => 'Defective', 'changed_mind' => 'Changed mind'];

    public const DISPOSITIONS = ['restock' => 'Back on the shelf', 'defective_writeoff' => 'Written off as defective', 'supplier_claim' => 'Back to the supplier'];

    public const REFUNDS = ['cash', 'transfer', 'pos'];

    public function __construct(
        private Epochs $epochs,
        private Shifts $shifts,
        private Approvals $approvals,
        private Settings $settings,
        private AuditLog $audit,
        private Journal $journal,
        private Clock $clock,
    ) {}

    /**
     * A sale as the counter's Returns screen shows it: each line with what is left to return, and whether it
     * can still be voided here.
     *
     * @return array{id: string, receipt_number: string, recorded_at: string, business_day: string, cashier: string, total_kobo: int, kind: string, voided: bool, can_void: bool, why_not_void: ?string, within_window: bool, lines: list<array{line_id: string, item_id: string, name: string, qty: int, returned: int, returnable: int, unit_price_kobo: int}>, payments: list<array{method: string, amount_kobo: int}>}
     */
    public function find(Terminal $terminal, string $receiptNumber): array
    {
        /** @var object{id: string, receipt_number: string, recorded_at: string, business_day: string, total_kobo: int, kind: string, shift_id: string, cashier: ?string}|null $sale */
        $sale = DB::table('sales as s')->leftJoin('users as u', 'u.id', '=', 's.cashier_id')
            ->where('s.branch_id', $terminal->branch_id)->where('s.receipt_number', strtoupper(trim($receiptNumber)))
            ->first(['s.id', 's.receipt_number', 's.recorded_at', 's.business_day', 's.total_kobo', 's.kind', 's.shift_id', 'u.name as cashier']);
        if ($sale === null) {
            throw new SaleRefused("There is no sale {$receiptNumber} at this branch. Check the number on the receipt.");
        }
        if (! in_array($sale->kind, ['sale', 'back_entry'], true)) {
            throw new SaleRefused("{$sale->receipt_number} is a ".($sale->kind === 'void' ? 'void' : 'return').' slip, not a sale.');
        }
        $lines = $this->linesWithReturns($sale->id);
        $voided = DB::table('sales')->where('reverses_sale_id', $sale->id)->where('kind', 'void')->exists();
        $whyNot = $this->whyNotVoid($terminal, $sale->id, $sale->kind, $sale->shift_id, $voided);
        /** @var list<object{method: string, amount_kobo: int}> $payments */
        $payments = DB::table('payments')->where('sale_id', $sale->id)->orderBy('id')->get(['method', 'amount_kobo'])->all();

        return [
            'id' => $sale->id, 'receipt_number' => $sale->receipt_number, 'recorded_at' => $sale->recorded_at, 'business_day' => $sale->business_day,
            'cashier' => $sale->cashier ?? '', 'total_kobo' => (int) $sale->total_kobo, 'kind' => $sale->kind, 'voided' => $voided,
            'can_void' => $whyNot === null, 'why_not_void' => $whyNot, 'within_window' => $this->withinWindow($sale->business_day),
            'lines' => $lines,
            'payments' => array_map(fn (object $p) => ['method' => $p->method, 'amount_kobo' => (int) $p->amount_kobo], $payments),
        ];
    }

    /**
     * Voids a whole sale.
     *
     * @return array<string, mixed> The void as the counter shows and prints it.
     */
    public function void(Terminal $terminal, User $actor, string $saleId, string $reason, string $requestId, ?string $approvalToken): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SaleRefused('Say why the sale is voided.');
        }
        $existing = $this->existing($terminal, $requestId);
        if ($existing !== null) {
            return [...Sales::summary($existing), 'retried' => true];
        }
        $this->ready($terminal);

        $id = $this->transaction($terminal, $requestId, function () use ($terminal, $actor, $saleId, $reason, $requestId, $approvalToken): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['reverse-sale:'.$saleId]);
            /** @var object{id: string, kind: string, shift_id: string, receipt_number: string, customer_type: string, total_kobo: int, branch_id: string}|null $sale */
            $sale = DB::table('sales')->where('id', $saleId)->first(['id', 'kind', 'shift_id', 'receipt_number', 'customer_type', 'total_kobo', 'branch_id']);
            if ($sale === null || $sale->branch_id !== $terminal->branch_id) {
                throw new SaleRefused('There is no such sale at this branch.');
            }
            $voided = DB::table('sales')->where('reverses_sale_id', $sale->id)->where('kind', 'void')->exists();
            $whyNot = $this->whyNotVoid($terminal, $sale->id, $sale->kind, $sale->shift_id, $voided);
            if ($whyNot !== null) {
                throw new SaleRefused($whyNot);
            }
            [$approvalId, $approver] = $this->approve($terminal, $actor, $approvalToken, ApprovalAction::Void, $sale->id, null,
                "A manager must approve voiding {$sale->receipt_number}.");

            /** @var list<object{id: string, item_id: string, name_snapshot: string, colour_note: ?string, qty: int, unit_price_kobo: int, cost_snapshot_kobo: ?int, price_source: string}> $lines */
            $lines = DB::table('sale_lines')->where('sale_id', $sale->id)->orderBy('line_no')
                ->get(['id', 'item_id', 'name_snapshot', 'colour_note', 'qty', 'unit_price_kobo', 'cost_snapshot_kobo', 'price_source'])->all();
            /** @var list<object{method: string, amount_kobo: int, reference: ?string}> $payments */
            $payments = DB::table('payments')->where('sale_id', $sale->id)->orderBy('id')->get(['method', 'amount_kobo', 'reference'])->all();

            $voidId = $this->insertSale($terminal, $actor, 'void', $sale->id, $sale->customer_type, $sale->shift_id, $requestId,
                ['void' => $sale->id, 'reason' => $reason],
                array_map(fn (object $l) => ['item_id' => $l->item_id, 'name' => $l->name_snapshot, 'colour' => $l->colour_note, 'qty' => (int) $l->qty,
                    'unit_price_kobo' => (int) $l->unit_price_kobo, 'cost_snapshot_kobo' => $l->cost_snapshot_kobo === null ? null : (int) $l->cost_snapshot_kobo,
                    'price_source' => $l->price_source, 'reverses_line_id' => $l->id], $lines),
                array_map(fn (object $p) => ['method' => $p->method, 'amount_kobo' => (int) $p->amount_kobo, 'reference' => $p->reference], $payments));

            $this->audit->record(new AuditEntry(action: 'sale.voided', actorId: $actor->id, terminalId: $terminal->id, subjectType: 'sale', subjectId: $sale->id,
                after: ['void_id' => $voidId, 'receipt_number' => $sale->receipt_number, 'total_kobo' => (int) $sale->total_kobo, 'reason' => $reason,
                    'approved_by' => $approver, 'approval_id' => $approvalId]));

            return $voidId;
        });

        return $this->finished($id);
    }

    /**
     * Returns some lines of a sale, or, for the owner only, items with no receipt.
     *
     * @param  array{request_id: string, receipt_number: ?string, lines: list<array{line_id?: string, item_id?: string, qty: int, unit_price_kobo?: int}>, reason: string, disposition: string, refund_method: string, refund_reference: ?string, approval_token: ?string}  $data
     * @return array<string, mixed> The return as the counter shows and prints it.
     */
    public function return(Terminal $terminal, User $actor, array $data): array
    {
        if (! isset(self::REASONS[$data['reason']])) {
            throw new SaleRefused('Choose why it came back: wrong item, defective or changed mind.');
        }
        if (! isset(self::DISPOSITIONS[$data['disposition']])) {
            throw new SaleRefused('Choose where it goes: back on the shelf, written off or back to the supplier.');
        }
        if (! in_array($data['refund_method'], self::REFUNDS, true)) {
            throw new SaleRefused('Choose how the money goes back: cash, transfer or POS.');
        }
        if ($data['lines'] === []) {
            throw new SaleRefused('Choose what comes back.');
        }
        $existing = $this->existing($terminal, $data['request_id']);
        if ($existing !== null) {
            return [...Sales::summary($existing), 'retried' => true];
        }
        $this->ready($terminal);

        $id = $this->transaction($terminal, $data['request_id'], function () use ($terminal, $actor, $data): string {
            $withReceipt = $data['receipt_number'] !== null && trim($data['receipt_number']) !== '';
            [$original, $lines] = $withReceipt ? $this->linesFromReceipt($terminal, (string) $data['receipt_number'], $data['lines']) : [null, $this->linesWithoutReceipt($data['lines'])];
            $refund = array_sum(array_map(fn (array $l) => $l['qty'] * $l['unit_price_kobo'], $lines));
            $what = $original === null ? 'a return with no receipt' : "the return on {$original->receipt_number}";
            [$approvalId, $approver] = $this->approve($terminal, $actor, $data['approval_token'], ApprovalAction::Return, $data['request_id'], $refund,
                $original === null ? 'Only the owner can take back goods with no receipt. Ask the owner to approve.' : "A manager must approve {$what}.",
                ownerOnly: $original === null);

            $shift = $this->shifts->openFor($terminal, $actor->id);
            $returnId = $this->insertSale($terminal, $actor, 'return', $original?->id, $original === null ? 'retail' : $original->customer_type, $shift->id, $data['request_id'],
                ['return' => $original?->id, 'reason' => $data['reason'], 'disposition' => $data['disposition']], $lines,
                [['method' => $data['refund_method'], 'amount_kobo' => $refund, 'reference' => $data['refund_method'] === 'transfer' ? $data['refund_reference'] : null]]);

            $at = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            $returnLines = DB::table('sale_lines')->where('sale_id', $returnId)->orderBy('line_no')->get(['id', 'reverses_line_id', 'qty']);
            foreach ($returnLines as $line) {
                DB::table('returns')->insert([
                    'id' => Ulid::generate(), 'sale_id' => $returnId, 'sale_line_id' => $line->id, 'original_line_id' => $line->reverses_line_id,
                    'qty' => -(int) $line->qty, 'reason_code' => $data['reason'], 'disposition' => $data['disposition'],
                    'approved_by' => $approver ?? ($actor->role->canApprove() ? $actor->id : null), 'approval_id' => $approvalId, 'at' => $at,
                ]);
            }
            $this->audit->record(new AuditEntry(action: 'sale.returned', actorId: $actor->id, terminalId: $terminal->id, subjectType: 'sale', subjectId: $returnId,
                after: ['receipt_number' => $original?->receipt_number, 'refund_kobo' => $refund, 'refund_method' => $data['refund_method'], 'lines' => count($lines),
                    'reason' => $data['reason'], 'disposition' => $data['disposition'], 'no_receipt' => $original === null, 'approved_by' => $approver]));

            return $returnId;
        });

        return $this->finished($id);
    }

    /**
     * @return list<array{line_id: string, item_id: string, name: string, qty: int, returned: int, returnable: int, unit_price_kobo: int}>
     */
    private function linesWithReturns(string $saleId): array
    {
        /** @var list<object{id: string, item_id: string, name_snapshot: string, qty: int, unit_price_kobo: int, returned: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT l.id, l.item_id, l.name_snapshot, l.qty, l.unit_price_kobo,
                   coalesce((SELECT -sum(r.qty) FROM sale_lines r JOIN sales s ON s.id = r.sale_id WHERE r.reverses_line_id = l.id AND s.kind = 'return'), 0) AS returned
            FROM sale_lines l WHERE l.sale_id = ? ORDER BY l.line_no
            SQL, [$saleId]);

        return array_map(fn (object $r) => ['line_id' => $r->id, 'item_id' => $r->item_id, 'name' => $r->name_snapshot, 'qty' => (int) $r->qty,
            'returned' => (int) $r->returned, 'returnable' => max(0, (int) $r->qty - (int) $r->returned), 'unit_price_kobo' => (int) $r->unit_price_kobo], $rows);
    }

    /**
     * The lines to return from a receipt, each locked and checked against what is left to return.
     *
     * @param  list<array{line_id?: string, item_id?: string, qty: int, unit_price_kobo?: int}>  $wanted
     * @return array{0: object{id: string, receipt_number: string, customer_type: string}, 1: list<array{item_id: string, name: string, colour: ?string, qty: int, unit_price_kobo: int, cost_snapshot_kobo: ?int, price_source: string, reverses_line_id: string}>}
     */
    private function linesFromReceipt(Terminal $terminal, string $receiptNumber, array $wanted): array
    {
        /** @var object{id: string, receipt_number: string, customer_type: string, kind: string, business_day: string}|null $sale */
        $sale = DB::table('sales')->where('branch_id', $terminal->branch_id)->where('receipt_number', strtoupper(trim($receiptNumber)))
            ->first(['id', 'receipt_number', 'customer_type', 'kind', 'business_day']);
        if ($sale === null || ! in_array($sale->kind, ['sale', 'back_entry'], true)) {
            throw new SaleRefused("There is no sale {$receiptNumber} at this branch. Check the number on the receipt.");
        }
        if (DB::table('sales')->where('reverses_sale_id', $sale->id)->where('kind', 'void')->exists()) {
            throw new SaleRefused("{$sale->receipt_number} was voided, so there is nothing to return.");
        }
        if (! $this->withinWindow($sale->business_day)) {
            throw new SaleRefused("{$sale->receipt_number} is older than the shop's return window.");
        }
        $ids = [];
        foreach ($wanted as $w) {
            $id = $w['line_id'] ?? '';
            if ($w['qty'] < 1) {
                throw new SaleRefused('Choose how many of each item come back.');
            }
            $ids[$id] = ($ids[$id] ?? 0) + $w['qty'];
        }
        // Each line's lock, in one order, so two returns of the same sale wait for each other and never deadlock.
        $sorted = array_keys($ids);
        sort($sorted);
        foreach ($sorted as $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['return-line:'.$id]);
        }
        $available = [];
        foreach ($this->linesWithReturns($sale->id) as $line) {
            $available[$line['line_id']] = $line;
        }
        /** @var array<string, object{id: string, item_id: string, name_snapshot: string, colour_note: ?string, unit_price_kobo: int, cost_snapshot_kobo: ?int, price_source: string}> $originals */
        $originals = DB::table('sale_lines')->where('sale_id', $sale->id)->whereIn('id', $sorted)
            ->get(['id', 'item_id', 'name_snapshot', 'colour_note', 'unit_price_kobo', 'cost_snapshot_kobo', 'price_source'])->keyBy('id')->all();
        $lines = [];
        foreach ($ids as $id => $qty) {
            $line = $available[$id] ?? null;
            $original = $originals[$id] ?? null;
            if ($line === null || $original === null) {
                throw new SaleRefused("That item is not on {$sale->receipt_number}.");
            }
            if ($qty > $line['returnable']) {
                throw new SaleRefused($line['returnable'] === 0
                    ? "{$line['name']} has already been returned."
                    : "Only {$line['returnable']} of {$line['name']} can still be returned.");
            }
            $lines[] = ['item_id' => $original->item_id, 'name' => $original->name_snapshot, 'colour' => $original->colour_note, 'qty' => $qty,
                'unit_price_kobo' => (int) $original->unit_price_kobo, 'cost_snapshot_kobo' => $original->cost_snapshot_kobo === null ? null : (int) $original->cost_snapshot_kobo,
                'price_source' => $original->price_source, 'reverses_line_id' => $id];
        }

        return [$sale, $lines];
    }

    /**
     * Items taken back with no receipt: the owner's only (spec section 9) [F7]. They reverse today's cost.
     *
     * @param  list<array{line_id?: string, item_id?: string, qty: int, unit_price_kobo?: int}>  $wanted
     * @return list<array{item_id: string, name: string, colour: null, qty: int, unit_price_kobo: int, cost_snapshot_kobo: ?int, price_source: string, reverses_line_id: null}>
     */
    private function linesWithoutReceipt(array $wanted): array
    {
        $now = CarbonImmutable::now();
        $lines = [];
        foreach ($wanted as $w) {
            $itemId = $w['item_id'] ?? '';
            $price = $w['unit_price_kobo'] ?? 0;
            $name = DB::table('items')->where('id', $itemId)->value('display_name');
            if (! is_string($name)) {
                throw new SaleRefused('Choose the item that comes back.');
            }
            if ($w['qty'] < 1 || $price < 1) {
                throw new SaleRefused("Type how many {$name} come back, and the price paid.");
            }
            $lines[] = ['item_id' => $itemId, 'name' => $name, 'colour' => null, 'qty' => $w['qty'], 'unit_price_kobo' => $price,
                'cost_snapshot_kobo' => Costs::current($itemId, $now), 'price_source' => 'typed', 'reverses_line_id' => null];
        }

        return $lines;
    }

    /**
     * The approval a cashier needs; a manager or the owner acts on their own. Returns [approval ID, approver].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function approve(Terminal $terminal, User $actor, ?string $token, ApprovalAction $action, string $subjectId, ?int $amountKobo, string $needed, bool $ownerOnly = false): array
    {
        $actorMay = $ownerOnly ? $actor->role === Role::Owner : $actor->role->canApprove();
        if ($actorMay) {
            return [null, $actor->id];
        }
        if ($token === null) {
            throw new ApprovalNeeded($needed, $action, $subjectId, null, $amountKobo);
        }
        try {
            $approvalId = $this->approvals->bind($terminal, $token, $action, $subjectId, null, $amountKobo);
        } catch (ApprovalRefused $e) {
            throw new ApprovalNeeded($e->getMessage(), $action, $subjectId, null, $amountKobo);
        }
        $approver = DB::table('approvals as a')->join('users as u', 'u.id', '=', 'a.approver_id')->where('a.id', $approvalId)->first(['u.id', 'u.role']);
        if ($ownerOnly && ($approver === null || $approver->role !== Role::Owner->value)) {
            throw new ApprovalNeeded('Only the owner can take back goods with no receipt. Ask the owner to approve.', $action, $subjectId, null, $amountKobo);
        }

        return [$approvalId, $approver === null ? null : (string) $approver->id];
    }

    private function whyNotVoid(Terminal $terminal, string $saleId, string $kind, string $shiftId, bool $voided): ?string
    {
        if ($kind !== 'sale') {
            return 'Only a sale rung at a counter can be voided. Use a return.';
        }
        if ($voided) {
            return 'This sale is already voided.';
        }
        if (DB::table('sales')->where('reverses_sale_id', $saleId)->where('kind', 'return')->exists()) {
            return 'Part of this sale has been returned, so it cannot be voided. Use a return.';
        }
        /** @var object{status: string, drawer_id: string}|null $shift */
        $shift = DB::table('shifts')->where('id', $shiftId)->first(['status', 'drawer_id']);
        if ($shift === null || $shift->status !== 'open') {
            return 'This sale\'s shift is closed, so it cannot be voided. Use a return.';
        }
        if ($shift->drawer_id !== $this->shifts->drawerFor($terminal)) {
            return 'This sale was taken into another counter\'s drawer. Void it there, or use a return.';
        }

        return null;
    }

    private function withinWindow(string $businessDay): bool
    {
        $days = $this->settings->get('returns.window_days');
        if (! is_int($days) || $days <= 0) {
            return true;
        }
        $zone = $this->settings->get('business.timezone');

        return CarbonImmutable::parse($businessDay)->addDays($days)->gte(CarbonImmutable::now(is_string($zone) ? $zone : 'Africa/Lagos')->startOfDay());
    }

    private function ready(Terminal $terminal): void
    {
        if (! $terminal->canSell()) {
            throw new SaleRefused('This device is for the back office. It cannot take returns.');
        }
        if ($this->journal->needed($terminal)) {
            throw new JournalNeeded;
        }
    }

    private function existing(Terminal $terminal, string $requestId): ?string
    {
        $id = DB::table('sales')->where('branch_id', $terminal->branch_id)->where('terminal_id', $terminal->id)
            ->where('idempotency_key', $requestId)->whereIn('kind', ['void', 'return'])->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Runs the reversal in one transaction, taking its receipt number inside it; a racing duplicate of the same
     * request finds the first.
     *
     * @param  \Closure(): string  $work
     */
    private function transaction(Terminal $terminal, string $requestId, \Closure $work): string
    {
        try {
            return DB::transaction($work, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existing($terminal, $requestId) ?? throw $e;
        }
    }

    /**
     * Writes the reversing sale: its receipt number, lines and payments with the signs reversed, and its slip.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{item_id: string, name: string, colour: ?string, qty: int, unit_price_kobo: int, cost_snapshot_kobo: ?int, price_source: string, reverses_line_id: ?string}>  $lines
     * @param  list<array{method: string, amount_kobo: int, reference: ?string}>  $payments
     */
    private function insertSale(Terminal $terminal, User $actor, string $kind, ?string $reverses, string $customerType, string $shiftId, string $requestId, array $payload, array $lines, array $payments): string
    {
        $epoch = $this->epochs->current($terminal);
        $receipt = ReceiptNumbers::next($terminal, $epoch);
        $at = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
        $total = array_sum(array_map(fn (array $l) => $l['qty'] * $l['unit_price_kobo'], $lines));
        $businessDay = DB::table('shifts')->where('id', $shiftId)->value('business_day');
        $saleId = Ulid::generate();
        DB::table('sales')->insert([
            'id' => $saleId, 'branch_id' => $terminal->branch_id, 'terminal_id' => $terminal->id, 'epoch' => $epoch,
            'receipt_no' => $receipt['number'], 'receipt_number' => $receipt['formatted'], 'idempotency_key' => $requestId,
            'payload_hash' => Hasher::hash([...$payload, 'lines' => $lines]), 'shift_id' => $shiftId, 'cashier_id' => $actor->id,
            'customer_type' => $customerType, 'kind' => $kind, 'total_kobo' => -$total, 'business_day' => $businessDay,
            'recorded_at' => $at, 'clock_state' => $this->clock->stamp(), 'reverses_sale_id' => $reverses,
        ]);
        foreach ($lines as $i => $l) {
            DB::table('sale_lines')->insert([
                'id' => Ulid::generate(), 'sale_id' => $saleId, 'line_no' => $i + 1, 'client_line_id' => "{$kind}-".($i + 1),
                'item_id' => $l['item_id'], 'name_snapshot' => $l['name'], 'colour_note' => $l['colour'], 'qty' => -$l['qty'],
                'unit_price_kobo' => $l['unit_price_kobo'], 'line_total_kobo' => -$l['qty'] * $l['unit_price_kobo'],
                'cost_snapshot_kobo' => $l['cost_snapshot_kobo'], 'price_source' => $l['price_source'],
                'flags' => json_encode([$kind => true], JSON_THROW_ON_ERROR), 'reverses_line_id' => $l['reverses_line_id'],
            ]);
        }
        foreach ($payments as $p) {
            DB::table('payments')->insert([
                'id' => Ulid::generate(), 'sale_id' => $saleId, 'method' => $p['method'], 'amount_kobo' => -$p['amount_kobo'],
                'reference' => $p['reference'], 'confirmed_by' => $p['method'] === 'transfer' ? $actor->id : null,
                'confirmed_at' => $p['method'] === 'transfer' ? $at : null,
            ]);
        }
        DB::table('print_jobs')->insert([
            'id' => Ulid::generate(), 'sale_id' => $saleId, 'terminal_id' => $terminal->id,
            'kind' => 'receipt', 'status' => 'pending', 'attempts' => 0, 'created_at' => $at,
        ]);

        return $saleId;
    }

    /**
     * After commit: the slip prints, and the counter gets the reversal to show.
     *
     * @return array<string, mixed>
     */
    private function finished(string $saleId): array
    {
        $printJobId = DB::table('print_jobs')->where('sale_id', $saleId)->where('kind', 'receipt')->value('id');
        if (is_string($printJobId)) {
            event(new SaleFinished($saleId, $printJobId));
        }

        return Sales::summary($saleId);
    }
}

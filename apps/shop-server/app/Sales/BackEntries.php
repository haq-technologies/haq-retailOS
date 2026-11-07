<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Hasher;
use App\Catalogue\Merges;
use App\Cost\Costs;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Shifts\Shifts;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Back entries for the lost window (spec section 9) [F1]. After a restore, the sales a counter's journal
 * holds and the restored data lacks are listed on the lost-window screen, and a manager re-enters each one
 * as a back entry. It keeps the receipt number the customer holds, its original time and business day, its
 * cashier, lines and payments; it prints nothing, because the customer already has the receipt. It is
 * flagged as back-dated and audited. Its epoch ended with the restore, so no new sale can take that number.
 *
 * The paper-pad back entries of the downtime procedure come with the MVP's back-entry screen.
 */
final readonly class BackEntries
{
    public function __construct(private Shifts $shifts, private AuditLog $audit) {}

    /**
     * Re-enters a lost sale. An item the restored data lacks (one created at the counter after the last
     * backup) is replaced by the item the manager chooses for that line; the line still keeps the name the
     * receipt printed. Re-entering the same sale twice gives the first back entry.
     *
     * @param  array<int, string>  $replacements  line index => the item to record instead
     * @return array{sale_id: string, receipt_number: string, already: bool}
     */
    public function reEnter(string $lostSaleId, User $actor, array $replacements = []): array
    {
        /** @var object{id: string, terminal_id: string, receipt_number: string, epoch: int, receipt_no: int, sold_at: string, business_day: string, cashier_id: ?string, cashier_name: ?string, customer_type: string, total_kobo: int, entry: string}|null $lost */
        $lost = DB::table('lost_sales')->where('id', $lostSaleId)->first();
        if ($lost === null) {
            throw new SaleRefused('That lost sale is not on the list.');
        }
        $done = DB::table('back_entries')->where('lost_sale_id', $lost->id)->value('sale_id');
        if (is_string($done)) {
            return ['sale_id' => $done, 'receipt_number' => $lost->receipt_number, 'already' => true];
        }
        $terminal = Terminal::query()->findOrFail($lost->terminal_id);
        if (DB::table('sales')->where('terminal_id', $terminal->id)->where('epoch', $lost->epoch)->where('receipt_no', $lost->receipt_no)->exists()) {
            throw new SaleRefused("{$lost->receipt_number} is already in the records, so there is nothing to re-enter.");
        }

        /** @var array{lines: list<array{item_id: ?string, name: string, colour: ?string, qty: int, unit_price_kobo: int}>, payments: list<array{method: string, amount_kobo: int, tendered_kobo: ?int, reference: ?string}>} $entry */
        $entry = json_decode($lost->entry, true, flags: JSON_THROW_ON_ERROR);
        $items = $this->items($entry['lines'], $replacements);
        $total = array_sum(array_map(fn (array $l) => $l['qty'] * $l['unit_price_kobo'], $entry['lines']));
        $paid = array_sum(array_column($entry['payments'], 'amount_kobo'));
        if ($total !== (int) $lost->total_kobo || $paid !== $total) {
            throw new SaleRefused("The journal's copy of {$lost->receipt_number} does not add up, so it cannot be re-entered as it is.");
        }

        $cashierId = is_string($lost->cashier_id) && DB::table('users')->where('id', $lost->cashier_id)->exists() ? $lost->cashier_id : $actor->id;
        $soldAt = CarbonImmutable::parse($lost->sold_at);
        $at = $soldAt->format('Y-m-d H:i:s.uP');
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');

        try {
            $saleId = DB::transaction(function () use ($lost, $terminal, $entry, $items, $cashierId, $soldAt, $at, $now, $actor): string {
                $shift = $this->shifts->forBackEntry($terminal, $lost->business_day, $actor->id);
                $saleId = Ulid::generate();
                DB::table('sales')->insert([
                    'id' => $saleId, 'branch_id' => $terminal->branch_id, 'terminal_id' => $terminal->id,
                    'epoch' => $lost->epoch, 'receipt_no' => $lost->receipt_no, 'receipt_number' => $lost->receipt_number,
                    'idempotency_key' => 'back-entry-'.$lost->id, 'payload_hash' => Hasher::hash($entry),
                    'shift_id' => $shift, 'cashier_id' => $cashierId, 'customer_type' => $lost->customer_type,
                    'kind' => 'back_entry', 'total_kobo' => $lost->total_kobo, 'business_day' => $lost->business_day,
                    'recorded_at' => $at, 'clock_state' => 'trusted',
                ]);
                foreach ($entry['lines'] as $i => $line) {
                    DB::table('sale_lines')->insert([
                        'id' => Ulid::generate(), 'sale_id' => $saleId, 'line_no' => $i + 1, 'client_line_id' => "back-entry-{$i}",
                        'item_id' => $items[$i], 'name_snapshot' => mb_substr($line['name'], 0, 160), 'colour_note' => $line['colour'],
                        'qty' => $line['qty'], 'unit_price_kobo' => $line['unit_price_kobo'], 'line_total_kobo' => $line['qty'] * $line['unit_price_kobo'],
                        'cost_snapshot_kobo' => Costs::current($items[$i], $soldAt), 'price_source' => 'typed',
                        'flags' => json_encode(['back_entry' => true], JSON_THROW_ON_ERROR),
                    ]);
                }
                foreach ($entry['payments'] as $p) {
                    $cash = $p['method'] === 'cash';
                    $transfer = $p['method'] === 'transfer';
                    DB::table('payments')->insert([
                        'id' => Ulid::generate(), 'sale_id' => $saleId, 'method' => $p['method'], 'amount_kobo' => $p['amount_kobo'],
                        'tendered_kobo' => $cash ? ($p['tendered_kobo'] ?? $p['amount_kobo']) : null,
                        'change_kobo' => $cash ? max(0, ($p['tendered_kobo'] ?? $p['amount_kobo']) - $p['amount_kobo']) : null,
                        'reference' => $transfer ? $p['reference'] : null, 'alert_seen' => $transfer ? true : null,
                        'confirmed_by' => $transfer ? $cashierId : null, 'confirmed_at' => $transfer ? $at : null,
                    ]);
                }
                DB::table('back_entries')->insert([
                    'id' => Ulid::generate(), 'sale_id' => $saleId, 'lost_sale_id' => $lost->id, 'source' => 'lost_window',
                    'reference' => $lost->receipt_number, 'sold_at' => $at, 'entered_by' => $actor->id, 'entered_at' => $now,
                ]);
                $this->audit->record(new AuditEntry(action: 'sale.back_entered', actorId: $actor->id, subjectType: 'sale', subjectId: $saleId,
                    after: ['receipt_number' => $lost->receipt_number, 'sold_at' => $at, 'business_day' => $lost->business_day,
                        'total_kobo' => (int) $lost->total_kobo, 'lines' => count($entry['lines']), 'source' => 'lost_window']));

                return $saleId;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two managers re-entered the same sale at once; the first one counts.
            $done = DB::table('back_entries')->where('lost_sale_id', $lost->id)->value('sale_id');
            if (! is_string($done)) {
                throw $e;
            }

            return ['sale_id' => $done, 'receipt_number' => $lost->receipt_number, 'already' => true];
        }

        return ['sale_id' => $saleId, 'receipt_number' => $lost->receipt_number, 'already' => false];
    }

    /**
     * The item each line is recorded under: the journal's, followed through any merge, or the one chosen
     * for a line whose item the restored data lacks.
     *
     * @param  list<array{item_id: ?string, name: string}>  $lines
     * @param  array<int, string>  $replacements
     * @return array<int, string>
     */
    private function items(array $lines, array $replacements): array
    {
        $items = [];
        $missing = [];
        foreach ($lines as $i => $line) {
            $id = $replacements[$i] ?? $line['item_id'];
            if ($id === null || ! DB::table('items')->where('id', $id)->exists()) {
                $missing[] = $i + 1;

                continue;
            }
            $items[$i] = Merges::rootOf($id);
        }
        if ($missing !== []) {
            throw new BackEntryNeedsItems($missing);
        }

        return $items;
    }
}

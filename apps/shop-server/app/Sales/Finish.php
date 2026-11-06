<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Catalogue\Models\Item;
use App\Cost\Costs;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use App\Shifts\Shifts;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finish (spec section 7) [F5, F1, F4, F14, S8]. The server recalculates every amount and, in one
 * transaction, writes the receipt number, the sale, its lines, payments, timings, the audit entry and a
 * pending print job. Printing and anything else outside the database happen after commit, so a deadlock
 * retry can never print twice.
 *
 * - Same key, same cart (a retry or a double tap): the original sale comes back, never an error, even when
 *   the two requests race.
 * - Same key, changed cart: refused in plain words, and nothing is created.
 * - A failure rolls back whole: no half-sale and no used receipt number. It is logged afterwards, on a
 *   separate connection, so logging can never block the counter.
 */
final class Finish
{
    /** Tests only: called inside the transaction right after the receipt number is taken (the crash tests). */
    public static ?Closure $afterNumberTaken = null;

    public function __construct(
        private readonly Epochs $epochs,
        private readonly Shifts $shifts,
        private readonly Settings $settings,
        private readonly AuditLog $audit,
        private readonly PriceRules $prices,
    ) {}

    /**
     * @return array<string, mixed> The sale as the counter shows and prints it.
     */
    public function finish(Terminal $terminal, User $cashier, SaleRequest $request): array
    {
        $hash = $request->payloadHash();

        try {
            if (! $terminal->canSell()) {
                throw new SaleRefused('This device is for the back office. It cannot sell.');
            }

            $existing = $this->existing($terminal, $request->idempotencyKey);
            if ($existing !== null) {
                return $this->retried($existing, $hash);
            }

            $this->checkPayments($request);
            $items = $this->items($request);

            $saleId = DB::transaction(function () use ($terminal, $cashier, $request, $hash, $items): string {
                $epoch = $this->epochs->current($terminal);
                $receipt = ReceiptNumbers::next($terminal, $epoch);

                // The counter row is locked now, so a racing duplicate of this key waits here and then finds the first.
                if ($this->existing($terminal, $request->idempotencyKey) !== null) {
                    throw new DuplicateSale;
                }
                if (self::$afterNumberTaken !== null) {
                    (self::$afterNumberTaken)();
                }

                $lines = $this->prices->check($terminal, $cashier, $request, $items);
                $shift = $this->shifts->openFor($terminal, $cashier->id);
                $now = CarbonImmutable::now();
                $at = $now->format('Y-m-d H:i:s.uP');
                $saleId = Ulid::generate();

                DB::table('sales')->insert([
                    'id' => $saleId, 'branch_id' => $terminal->branch_id, 'terminal_id' => $terminal->id,
                    'epoch' => $epoch, 'receipt_no' => $receipt['number'], 'receipt_number' => $receipt['formatted'],
                    'idempotency_key' => $request->idempotencyKey, 'payload_hash' => $hash,
                    'shift_id' => $shift->id, 'cashier_id' => $cashier->id, 'customer_type' => $request->customerType,
                    'kind' => 'sale', 'total_kobo' => $request->totalKobo(), 'business_day' => $shift->business_day,
                    'recorded_at' => $at, 'clock_state' => 'trusted',
                ]);

                foreach ($request->lines as $i => $line) {
                    $item = $items[$line['item_id']];
                    DB::table('sale_lines')->insert([
                        'id' => Ulid::generate(), 'sale_id' => $saleId, 'line_no' => $i + 1,
                        'client_line_id' => $line['client_line_id'], 'item_id' => $item->id,
                        'name_snapshot' => $item->display_name, 'colour_note' => $line['colour'],
                        'qty' => $line['qty'], 'unit_price_kobo' => $line['unit_price_kobo'],
                        'line_total_kobo' => $line['qty'] * $line['unit_price_kobo'],
                        'cost_snapshot_kobo' => Costs::current($item->id, $now),
                        'price_source' => $line['price_source'],
                        'approval_id' => $lines[$i]['approval_id'],
                        'flags' => json_encode((object) $lines[$i]['flags'], JSON_THROW_ON_ERROR),
                    ]);
                }

                foreach ($request->payments as $payment) {
                    $cash = $payment['method'] === 'cash';
                    DB::table('payments')->insert([
                        'id' => Ulid::generate(), 'sale_id' => $saleId, 'method' => $payment['method'],
                        'amount_kobo' => $payment['amount_kobo'],
                        'tendered_kobo' => $cash ? ($payment['tendered_kobo'] ?? $payment['amount_kobo']) : null,
                        'change_kobo' => $cash ? ($payment['tendered_kobo'] ?? $payment['amount_kobo']) - $payment['amount_kobo'] : null,
                        'reference' => $payment['method'] === 'transfer' ? $payment['reference'] : null,
                        'alert_seen' => $payment['method'] === 'transfer' ? (bool) $payment['alert_seen'] : null,
                        'confirmed_by' => $payment['method'] === 'transfer' ? $cashier->id : null,
                        'confirmed_at' => $payment['method'] === 'transfer' ? $at : null,
                    ]);
                }

                // Committed with the sale: a cut between commit and print leaves a recoverable receipt [F5].
                DB::table('print_jobs')->insert([
                    'id' => Ulid::generate(), 'sale_id' => $saleId, 'terminal_id' => $terminal->id,
                    'kind' => 'receipt', 'status' => 'pending', 'attempts' => 0, 'created_at' => $at,
                ]);

                DB::table('sale_timings')->insert([
                    'id' => Ulid::generate(), 'sale_id' => $saleId, 'terminal_id' => $terminal->id,
                    'started_at' => $request->startedAt === null ? null : CarbonImmutable::parse($request->startedAt)->format('Y-m-d H:i:s.uP'),
                    'finished_at' => $at,
                    'duration_ms' => $request->startedAt === null ? null : (int) max(0, CarbonImmutable::parse($request->startedAt)->diffInMilliseconds($now)),
                    'lines' => json_encode(array_column($request->lines, 'added_by'), JSON_THROW_ON_ERROR),
                ]);

                if ($request->parkedSaleId !== null) {
                    $this->completeParked($request->parkedSaleId, $terminal, $cashier, $at);
                }

                $this->audit->record(new AuditEntry(
                    action: 'sale.finished', actorId: $cashier->id, terminalId: $terminal->id, subjectType: 'sale', subjectId: $saleId,
                    after: [
                        'receipt_number' => $receipt['formatted'], 'total_kobo' => $request->totalKobo(), 'lines' => count($request->lines),
                        'payments' => array_values(array_unique(array_column($request->payments, 'method'))),
                    ],
                ));

                return $saleId;
            }, attempts: 3);

            return Sales::summary($saleId);
        } catch (DuplicateSale|UniqueConstraintViolationException $e) {
            $existing = $this->existing($terminal, $request->idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $this->retried($existing, $hash);
        } catch (SaleChanged $e) {
            throw $e;
        } catch (SaleRefused $e) {
            $this->logFailure($terminal, $cashier, $request, 'refused', $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->logFailure($terminal, $cashier, $request, 'failed', $e::class);
            throw $e;
        }
    }

    private function checkPayments(SaleRequest $request): void
    {
        $paid = 0;
        foreach ($request->payments as $payment) {
            $paid += $payment['amount_kobo'];
            if ($payment['method'] === 'cash' && $payment['tendered_kobo'] !== null && $payment['tendered_kobo'] < $payment['amount_kobo']) {
                throw new SaleRefused('The cash given is less than the cash due.');
            }
            if ($payment['method'] === 'transfer' && $payment['alert_seen'] !== true && $this->settings->get('payments.transfer_alert_tick') === true) {
                throw new SaleRefused('Tick that you saw the transfer alert before you finish.');
            }
        }
        if ($paid !== $request->totalKobo()) {
            throw new SaleRefused('The payments do not add up to the total. Check the amounts.');
        }
    }

    /**
     * @return array<string, Item>
     */
    private function items(SaleRequest $request): array
    {
        $ids = array_values(array_unique(array_column($request->lines, 'item_id')));
        $items = Item::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
        foreach ($ids as $id) {
            $item = $items[$id] ?? null;
            if ($item === null) {
                throw new SaleRefused('An item in this sale is not in the catalogue. Search for it again.');
            }
            if (in_array($item->status, ['merged', 'archived'], true)) {
                throw new SaleRefused("{$item->display_name} was merged or archived. Search for it again.");
            }
        }

        /** @var array<string, Item> $items */
        return $items;
    }

    /**
     * @return object{id: string, payload_hash: string}|null
     */
    private function existing(Terminal $terminal, string $key): ?object
    {
        /** @var object{id: string, payload_hash: string}|null */
        return DB::table('sales')->where('branch_id', $terminal->branch_id)->where('terminal_id', $terminal->id)
            ->where('idempotency_key', $key)->first(['id', 'payload_hash']);
    }

    /**
     * @param  object{id: string, payload_hash: string}  $existing
     * @return array<string, mixed>
     */
    private function retried(object $existing, string $hash): array
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw new SaleChanged;
        }

        return [...Sales::summary($existing->id), 'retried' => true];
    }

    private function completeParked(string $parkedSaleId, Terminal $terminal, User $cashier, string $at): void
    {
        $updated = DB::table('parked_sales')->where('id', $parkedSaleId)->where('branch_id', $terminal->branch_id)
            ->whereIn('status', ['open', 'resumed'])->update(['status' => 'completed']);
        if ($updated === 0) {
            throw new SaleRefused('That held sale was already finished or discarded on another counter.');
        }
        DB::table('parked_sale_events')->insert([
            'id' => Ulid::generate(), 'parked_sale_id' => $parkedSaleId, 'type' => 'completed',
            'actor_id' => $cashier->id, 'terminal_id' => $terminal->id, 'at' => $at,
        ]);
    }

    private function logFailure(Terminal $terminal, User $cashier, SaleRequest $request, string $what, string $reason): void
    {
        $this->audit->recordFailure(new AuditEntry(
            action: "sale.{$what}", actorId: $cashier->id, terminalId: $terminal->id,
            after: ['idempotency_key' => $request->idempotencyKey, 'reason' => $reason, 'total_kobo' => $request->totalKobo()],
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Sales;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The counter journal after a restore (spec sections 4 and 9) [F1, S14]. Each counter keeps seven days of
 * the sales it finished. When the server has been restored, a counter reports its journal before it sells
 * again:
 *
 * 1. It sends the highest receipt number it holds in each epoch. The server answers with what it lacks:
 *    for each such epoch, the highest number it still has.
 * 2. It sends the journal's sales after those numbers. They become lost sales, which a manager re-enters
 *    on the lost-window screen under their original receipt numbers.
 *
 * Either way the counter's epoch is raised past anything its journal shows, so a receipt number can never
 * repeat, even when the epoch file on the backup disk was older than the counter's last sale. A report
 * that finds a journal ahead of a server nobody restored (a restore or reinstall done by hand) raises the
 * epoch too. Receipt numbers are per counter, so each counter waits only for its own report: a broken
 * counter never stops the others selling.
 */
final readonly class Journal
{
    /** The epochs that begin after a restore, a server replacement or a reinstall. */
    public const RESTORE_REASONS = ['restore', 'replacement', 'reinstall'];

    /** A receipt number: terminal, epoch, number (T01-E2-001001). */
    private const RECEIPT = '/^([A-Z][0-9]{2})-E([0-9]{1,6})-([0-9]{6,12})$/';

    public function __construct(private Epochs $epochs, private AuditLog $audit, private Settings $settings) {}

    /** Whether this counter reports its journal before it sells again: its epoch began with a restore, and it has not reported in it. */
    public function needed(Terminal $terminal): bool
    {
        /** @var object{epoch: int, reason: string}|null $current */
        $current = DB::table('epochs')->where('terminal_id', $terminal->id)->orderByDesc('epoch')->first(['epoch', 'reason']);
        if ($current === null || ! in_array($current->reason, self::RESTORE_REASONS, true)) {
            return false;
        }

        return ! DB::table('journal_reports')->where('terminal_id', $terminal->id)->where('epoch', $current->epoch)->exists();
    }

    /**
     * Takes a counter's report. With no entries, it only checks: when the server lacks sales the journal
     * holds, it answers with what to send (each epoch and the number to send entries after) and records
     * nothing. With the entries, or when nothing is missing, it stores the lost sales, raises the epoch if
     * the journal is ahead, and records the report.
     *
     * @param  array<string, mixed>  $data  {highest: {epoch: number}, entries?: list<journal entry>}
     * @return array{send: list<array{epoch: int, after: int}>}|array{ok: true, epoch: ?int, missing: int, raised_to: ?int}
     */
    public function report(Terminal $terminal, array $data): array
    {
        [$highest, $entries] = $this->parse($terminal, $data);
        $sending = array_key_exists('entries', $data);

        $lacking = $this->lacking($terminal, $highest);
        if ($lacking !== [] && ! $sending) {
            return ['send' => array_map(fn (int $epoch, int $after) => ['epoch' => $epoch, 'after' => $after], array_keys($lacking), $lacking)];
        }

        return DB::transaction(function () use ($terminal, $highest, $entries, $lacking): array {
            // The same lock that begins a counter's first epoch, so a raise and a first sale never cross.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['epoch:'.$terminal->id]);
            $wasNeeded = $this->needed($terminal);
            $reportId = Ulid::generate();
            $restoreId = DB::table('restores')->orderByDesc('at')->value('id');
            $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');

            $lost = [];
            foreach ($entries as $entry) {
                $after = $lacking[$entry['epoch']] ?? null;
                if ($after === null || $entry['number'] <= $after || $this->exists($terminal, $entry['epoch'], $entry['number']) || $this->known($terminal, $entry['epoch'], $entry['number'])) {
                    continue;
                }
                $lost[] = $entry;
            }

            [$epoch, $raised] = $this->raise($terminal, $highest);
            if (! $wasNeeded && $lost === [] && $raised === null) {
                return ['ok' => true, 'epoch' => $epoch, 'missing' => 0, 'raised_to' => null];
            }

            DB::table('journal_reports')->insert([
                'id' => $reportId, 'terminal_id' => $terminal->id, 'restore_id' => is_string($restoreId) ? $restoreId : null,
                'epoch' => $epoch ?? 0, 'highest' => json_encode((object) $highest, JSON_THROW_ON_ERROR),
                'missing' => count($lost), 'raised_to' => $raised, 'reported_at' => $now,
            ]);
            foreach ($lost as $entry) {
                DB::table('lost_sales')->insertOrIgnore([
                    'id' => Ulid::generate(), 'terminal_id' => $terminal->id, 'journal_report_id' => $reportId,
                    'receipt_number' => $entry['receipt_number'], 'epoch' => $entry['epoch'], 'receipt_no' => $entry['number'],
                    'sold_at' => $entry['at'], 'business_day' => $entry['business_day'],
                    'cashier_id' => $entry['cashier_id'], 'cashier_name' => $entry['cashier'],
                    'customer_type' => $entry['customer_type'], 'total_kobo' => $entry['total_kobo'],
                    'entry' => json_encode(['lines' => $entry['lines'], 'payments' => $entry['payments']], JSON_THROW_ON_ERROR),
                ]);
            }
            $this->audit->record(new AuditEntry(action: 'recovery.journal_reported', terminalId: $terminal->id, subjectType: 'terminal', subjectId: $terminal->id,
                after: ['epoch' => $epoch, 'highest' => $highest, 'missing' => array_column($lost, 'receipt_number'), 'raised_to' => $raised]));

            return ['ok' => true, 'epoch' => $epoch, 'missing' => count($lost), 'raised_to' => $raised];
        });
    }

    /**
     * For each epoch the journal is ahead in, the highest number the server holds there (0 for none).
     *
     * @param  array<int, int>  $highest
     * @return array<int, int>
     */
    private function lacking(Terminal $terminal, array $highest): array
    {
        $lacking = [];
        foreach ($highest as $epoch => $number) {
            $held = DB::table('sales')->where('terminal_id', $terminal->id)->where('epoch', $epoch)->max('receipt_no');
            $held = is_numeric($held) ? (int) $held : 0;
            if ($number > $held) {
                $lacking[$epoch] = $held;
            }
        }
        ksort($lacking);

        return $lacking;
    }

    private function exists(Terminal $terminal, int $epoch, int $number): bool
    {
        return DB::table('sales')->where('branch_id', $terminal->branch_id)->where('terminal_id', $terminal->id)
            ->where('epoch', $epoch)->where('receipt_no', $number)->exists();
    }

    /** A lost sale an earlier report (or the same journal reported twice at once) already stored. */
    private function known(Terminal $terminal, int $epoch, int $number): bool
    {
        return DB::table('lost_sales')->where('terminal_id', $terminal->id)->where('epoch', $epoch)->where('receipt_no', $number)->exists();
    }

    /**
     * Begins a new epoch when the journal shows one at or above the counter's current epoch with numbers
     * the server never gave out there: one more than the highest anywhere, numbering from 1,001 [S14].
     *
     * @param  array<int, int>  $highest
     * @return array{0: ?int, 1: ?int} the counter's epoch now, and the epoch begun (if any)
     */
    private function raise(Terminal $terminal, array $highest): array
    {
        $current = DB::table('epochs')->where('terminal_id', $terminal->id)->max('epoch');
        $current = is_numeric($current) ? (int) $current : null;
        if ($highest === []) {
            return [$current, null];
        }
        $top = max(array_keys($highest));
        if ($current !== null) {
            /** @var int|string|null $last */
            $last = DB::table('receipt_counters')->where('terminal_id', $terminal->id)->where('epoch', $current)->lockForUpdate()->value('last_no');
            $ahead = $top > $current || ($top === $current && $highest[$top] > (int) $last);
            if (! $ahead) {
                return [$current, null];
            }
        }
        $next = max($top, $current ?? 0) + 1;
        $this->epochs->begin($terminal, $next, $current === null ? 'reinstall' : 'restore', afterNumber: 1000);
        $this->audit->record(new AuditEntry(action: 'recovery.epoch_raised', terminalId: $terminal->id, subjectType: 'terminal', subjectId: $terminal->id,
            before: ['epoch' => $current], after: ['epoch' => $next, 'journal_epoch' => $top, 'journal_number' => $highest[$top]]));

        return [$next, $next];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<int, int>, 1: list<array{receipt_number: string, epoch: int, number: int, at: string, business_day: string, cashier_id: ?string, cashier: ?string, customer_type: string, total_kobo: int, lines: list<array<string, mixed>>, payments: list<array<string, mixed>>}>}
     */
    private function parse(Terminal $terminal, array $data): array
    {
        $validator = Validator::make($data, [
            'highest' => ['present', 'array'],
            'highest.*' => ['integer', 'min:1'],
            'entries' => ['sometimes', 'array', 'max:5000'],
            'entries.*.receipt_number' => ['required', 'string', 'regex:'.self::RECEIPT],
            'entries.*.at' => ['required', 'date'],
            'entries.*.business_day' => ['nullable', 'date_format:Y-m-d'],
            'entries.*.cashier_id' => ['nullable', 'string', 'size:26'],
            'entries.*.cashier' => ['nullable', 'string', 'max:80'],
            'entries.*.customer_type' => ['nullable', 'in:retail,wholesale'],
            'entries.*.total_kobo' => ['required', 'integer', 'min:1'],
            'entries.*.lines' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.lines.*.item_id' => ['nullable', 'string', 'size:26'],
            'entries.*.lines.*.name' => ['required', 'string', 'max:160'],
            'entries.*.lines.*.colour' => ['nullable', 'string', 'max:40'],
            'entries.*.lines.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'entries.*.lines.*.unit_price_kobo' => ['required', 'integer', 'min:1'],
            'entries.*.payments' => ['required', 'array', 'min:1', 'max:5'],
            'entries.*.payments.*.method' => ['required', 'in:cash,transfer,pos'],
            'entries.*.payments.*.amount_kobo' => ['required', 'integer', 'min:1'],
            'entries.*.payments.*.tendered_kobo' => ['nullable', 'integer', 'min:1'],
            'entries.*.payments.*.reference' => ['nullable', 'string', 'max:60'],
        ]);
        if ($validator->fails()) {
            throw new SaleRefused('The counter journal could not be read: '.$validator->errors()->first());
        }

        $highest = [];
        /** @var array<int|string, int|string> $given */
        $given = is_array($data['highest']) ? $data['highest'] : [];
        foreach ($given as $epoch => $number) {
            if (is_numeric($epoch) && (int) $epoch >= 1) {
                $highest[(int) $epoch] = max($highest[(int) $epoch] ?? 0, (int) $number);
            }
        }

        $zone = $this->settings->get('business.timezone');
        $entries = [];
        // The validator has checked every field above; this names their types for the code below.
        /** @var list<array{receipt_number: string, at: string, business_day?: ?string, cashier_id?: ?string, cashier?: ?string, customer_type?: ?string, total_kobo: int|string, lines: list<array{item_id?: ?string, name: string, colour?: ?string, qty: int|string, unit_price_kobo: int|string}>, payments: list<array{method: string, amount_kobo: int|string, tendered_kobo?: int|string|null, reference?: ?string}>}> $raw */
        $raw = is_array($data['entries'] ?? null) ? $data['entries'] : [];
        foreach ($raw as $e) {
            preg_match(self::RECEIPT, $e['receipt_number'], $m);
            // Only this counter's own receipts count; a journal from another enrolment is ignored.
            if (($m[1] ?? null) !== $terminal->code) {
                continue;
            }
            $at = CarbonImmutable::parse($e['at'])->utc();
            $lines = $e['lines'];
            $payments = $e['payments'];
            $entry = [
                'receipt_number' => $e['receipt_number'], 'epoch' => (int) $m[2], 'number' => (int) $m[3],
                'at' => $at->format('Y-m-d H:i:s.uP'),
                'business_day' => is_string($e['business_day'] ?? null) ? $e['business_day'] : $at->setTimezone(is_string($zone) ? $zone : 'Africa/Lagos')->toDateString(),
                'cashier_id' => is_string($e['cashier_id'] ?? null) ? strtolower($e['cashier_id']) : null,
                'cashier' => is_string($e['cashier'] ?? null) ? $e['cashier'] : null,
                'customer_type' => is_string($e['customer_type'] ?? null) ? $e['customer_type'] : 'retail',
                'total_kobo' => (int) $e['total_kobo'],
                'lines' => array_map(fn (array $l) => [
                    'item_id' => is_string($l['item_id'] ?? null) ? strtolower($l['item_id']) : null, 'name' => $l['name'],
                    'colour' => is_string($l['colour'] ?? null) && trim($l['colour']) !== '' ? trim($l['colour']) : null,
                    'qty' => (int) $l['qty'], 'unit_price_kobo' => (int) $l['unit_price_kobo'],
                ], $lines),
                'payments' => array_map(fn (array $p) => [
                    'method' => $p['method'], 'amount_kobo' => (int) $p['amount_kobo'],
                    'tendered_kobo' => is_numeric($p['tendered_kobo'] ?? null) ? (int) $p['tendered_kobo'] : null,
                    'reference' => is_string($p['reference'] ?? null) && trim($p['reference']) !== '' ? trim($p['reference']) : null,
                ], $payments),
            ];
            $entries[] = $entry;
            $highest[$entry['epoch']] = max($highest[$entry['epoch']] ?? 0, $entry['number']);
        }
        ksort($highest);

        return [$highest, $entries];
    }
}

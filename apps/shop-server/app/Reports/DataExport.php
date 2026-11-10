<?php

declare(strict_types=1);

namespace App\Reports;

use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * The owner's data export (spec section 14, Pilot success thresholds: "either way the owner receives his
 * data export") [F10, D57]: the shop's whole record in one ZIP file of CSV files that open in Excel, cost
 * included, with a README naming each file and its SHA-256, and the audit code it was made at. Only the
 * owner downloads it; HAQ makes it on the server with shop:export. PINs and device secrets never leave.
 * Each file is written a row at a time, so a long history never sits in memory.
 */
final readonly class DataExport
{
    public function __construct(private Flags $flags, private Settings $settings) {}

    public function zone(): string
    {
        $zone = $this->settings->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }

    /** A fresh path for a ZIP file in the system's temporary folder, never one that exists. */
    public static function temporaryPath(): string
    {
        return sys_get_temp_dir().'/haq-data-'.bin2hex(random_bytes(12)).'.zip';
    }

    public function filename(): string
    {
        return 'haq-retail-os-data-'.CarbonImmutable::now($this->zone())->format('Y-m-d').'.zip';
    }

    /**
     * Writes the ZIP file.
     *
     * @return array{files: array<string, array{rows: int, sha256: string}>, root: ?string}
     */
    public function write(string $path, string $madeBy): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('This server cannot make ZIP files: the PHP zip extension is missing.');
        }
        $zone = $this->zone();
        $dir = sys_get_temp_dir().'/haq-export-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException("The export could not make its working folder {$dir}.");
        }

        $files = [];
        $root = null;
        try {
            foreach ($this->sheets($zone) as $name => [$header, $rows]) {
                $file = "{$dir}/{$name}";
                $handle = fopen($file, 'wb');
                if ($handle === false) {
                    throw new RuntimeException("The export could not write {$name}.");
                }
                $count = Csv::write($handle, $header, $rows);
                fclose($handle);
                $files[$name] = ['rows' => $count, 'sha256' => (string) hash_file('sha256', $file)];
            }
            /** @var object{hash: string}|null $root */
            $root = DB::selectOne('SELECT hash FROM audit_roots ORDER BY seq DESC LIMIT 1');
            file_put_contents("{$dir}/README.txt", $this->readme($files, $root?->hash, $madeBy));

            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("The export could not write {$path}.");
            }
            $zip->addFile("{$dir}/README.txt", 'README.txt');
            foreach (array_keys($files) as $name) {
                $zip->addFile("{$dir}/{$name}", $name);
            }
            if (! $zip->close()) {
                throw new RuntimeException("The export could not finish {$path}.");
            }
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }

        return ['files' => $files, 'root' => $root->hash ?? null];
    }

    /**
     * Each file: its header and its rows.
     *
     * @return array<string, array{list<string>, iterable<list<string|int|null>>}>
     */
    private function sheets(string $zone): array
    {
        return [
            'receipts.csv' => [['Receipt', 'Business day', 'Time', 'Counter', 'Cashier', 'Customer', 'Kind', 'Total (NGN)', 'Cash (NGN)', 'Transfer (NGN)', 'POS (NGN)', 'Clock', 'Receipt printed'], $this->receipts($zone)],
            'sales-lines.csv' => [Exports::salesHeader(true), Exports::sales(Period::named('all', $zone), true, $zone)],
            'payments.csv' => [['Receipt', 'Paid by', 'Amount (NGN)', 'Cash given (NGN)', 'Change (NGN)', 'Transfer reference', 'Alert seen', 'Confirmed by', 'Confirmed at'], $this->payments($zone)],
            'items.csv' => [Exports::itemsHeader(true), Exports::items(true, $zone)],
            'set-prices.csv' => [['Item code', 'Item', 'Customer', 'Set price (NGN)', 'From', 'Set by'], $this->prices($zone)],
            'costs.csv' => [['Item code', 'Item', 'Cost (NGN)', 'Counts from', 'Entered by', 'Undo of an earlier cost', 'Undone later'], $this->costs($zone)],
            'shifts.csv' => [['Counter', 'Business day', 'Opened', 'Opened by', 'Opening float (NGN)', 'Status', 'Closed'], $this->shifts($zone)],
            'drawer-log.csv' => [['When', 'Counter', 'What', 'Amount (NGN)', 'Reason', 'Receipt', 'Who', 'Approved by'], $this->drawer($zone)],
            'flags.csv' => [['When', 'Flag', 'What happened', 'Detail', 'Amount (NGN)', 'Cost (NGN)', 'Who', 'Approved by', 'Counter', 'Receipt', 'Seen by', 'Seen at'], $this->flagRows($zone)],
            'people.csv' => [['Name', 'Role', 'Active', 'Added', 'Deactivated'], $this->people($zone)],
            'audit-log.csv' => [['When', 'Chain', 'Entry', 'Who', 'Device', 'Action', 'Subject', 'Subject ID', 'Before', 'After', 'Sensitive before', 'Sensitive after', 'Hash'], $this->audit($zone)],
            'pilot-days.csv' => [['When', 'What', 'First day', 'Decision', 'Note', 'Who'], $this->pilotEvents($zone)],
            'pilot-notebook.csv' => [['Business day', 'Paid by', 'Notebook total (NGN)', 'Typed by', 'Typed at', 'Counts'], $this->notebook($zone)],
            'pilot-traces.csv' => [['Business day', 'Paid by', 'Amount (NGN)', 'Whose record was wrong', 'What happened', 'Receipt', 'Written by', 'Written at', 'Withdrawn'], $this->traces($zone)],
        ];
    }

    /**
     * @param  array<string, array{rows: int, sha256: string}>  $files
     */
    private function readme(array $files, ?string $root, string $madeBy): string
    {
        $shop = $this->settings->get('receipt.shop_name');
        $version = config('app.version');
        $about = [
            'receipts.csv' => 'One row per sale: its receipt number, day, counter, cashier, total and how it was paid.',
            'sales-lines.csv' => 'One row per item sold, with its price and cost (at sale or estimated later).',
            'payments.csv' => 'One row per payment, with cash given, change and transfer references.',
            'items.csv' => 'Every item in the catalogue, with its set prices, barcodes and current cost.',
            'set-prices.csv' => 'Every set price, in the order it was set.',
            'costs.csv' => 'Every cost entered, including undos.',
            'shifts.csv' => 'Every shift on each counter.',
            'drawer-log.csv' => 'Every time a cash drawer opened, and why.',
            'flags.csv' => 'Every flag the system raised, and who marked it as seen.',
            'people.csv' => 'Everyone who could sign in, and their role. PINs are never exported.',
            'audit-log.csv' => 'Every action, who did it, on which device and what changed, with the chain\'s hash.',
            'pilot-days.csv' => 'The measured pilot: when its 14 days started and the owner\'s decision.',
            'pilot-notebook.csv' => 'The measured pilot: the notebook\'s totals as typed (the newest for a day counts).',
            'pilot-traces.csv' => 'The measured pilot: each difference traced, and whose record was wrong.',
        ];
        $lines = [
            'HAQ Retail OS: the shop\'s data',
            is_string($shop) && $shop !== '' ? $shop : 'The shop',
            'Made '.CarbonImmutable::now($this->zone())->format('j F Y \a\t H:i').' by '.$madeBy.', on server version '.(is_string($version) ? $version : 'dev').'.',
            '',
            'Every file opens in Excel. Money is in naira with two decimals; times are the shop\'s ('.$this->zone().').',
            '',
        ];
        foreach ($files as $name => $file) {
            $lines[] = str_pad($name, 20).($about[$name] ?? '').' '.$file['rows'].' rows.';
        }
        $lines[] = '';
        $lines[] = 'Audit code when this was made: '.($root === null ? 'none yet' : implode(' ', str_split(strtoupper(substr($root, 0, 32)), 4))).'.';
        $lines[] = 'It matches the daily summary\'s audit code printed at the same moment, and the audit log\'s hashes prove no entry changed.';
        $lines[] = '';
        $lines[] = 'SHA-256 of each file, to check none changed after it was made:';
        foreach ($files as $name => $file) {
            $lines[] = "{$file['sha256']}  {$name}";
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function time(?string $at, string $zone): ?string
    {
        return $at === null ? null : CarbonImmutable::parse($at)->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    /** @return Generator<int, list<string|int|null>> */
    private function receipts(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT s.receipt_number, s.business_day, s.recorded_at, t.code AS counter, u.name AS cashier, s.customer_type, s.kind, s.total_kobo, s.clock_state,
                   coalesce(sum(p.amount_kobo) FILTER (WHERE p.method = 'cash'), 0) AS cash,
                   coalesce(sum(p.amount_kobo) FILTER (WHERE p.method = 'transfer'), 0) AS transfer,
                   coalesce(sum(p.amount_kobo) FILTER (WHERE p.method = 'pos'), 0) AS pos,
                   (SELECT j.status FROM print_jobs j WHERE j.sale_id = s.id AND j.kind = 'receipt' ORDER BY j.created_at LIMIT 1) AS printed
            FROM sales s JOIN terminals t ON t.id = s.terminal_id JOIN users u ON u.id = s.cashier_id LEFT JOIN payments p ON p.sale_id = s.id
            GROUP BY s.id, t.code, u.name ORDER BY s.recorded_at, s.receipt_number
            SQL);
        $kinds = ['sale' => 'Sale', 'back_entry' => 'Re-entered after a restore', 'void' => 'Void', 'return' => 'Return'];
        $printed = ['printed' => 'Printed', 'pending' => 'Waiting to print', 'failed' => 'Did not print'];
        foreach ($rows as $r) {
            /** @var object{receipt_number: string, business_day: string, recorded_at: string, counter: string, cashier: string, customer_type: string, kind: string, total_kobo: int|string, clock_state: string, cash: int|string, transfer: int|string, pos: int|string, printed: ?string} $r */
            yield [$r->receipt_number, $r->business_day, $this->time($r->recorded_at, $zone), $r->counter, $r->cashier, ucfirst($r->customer_type), $kinds[$r->kind] ?? $r->kind,
                Csv::naira((int) $r->total_kobo), Csv::naira((int) $r->cash), Csv::naira((int) $r->transfer), Csv::naira((int) $r->pos), ucfirst($r->clock_state),
                $r->printed === null ? '' : ($printed[$r->printed] ?? $r->printed)];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function payments(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT s.receipt_number, p.method, p.amount_kobo, p.tendered_kobo, p.change_kobo, p.reference, p.alert_seen, u.name AS confirmed_by, p.confirmed_at
            FROM payments p JOIN sales s ON s.id = p.sale_id LEFT JOIN users u ON u.id = p.confirmed_by
            ORDER BY s.recorded_at, s.receipt_number, p.method
            SQL);
        foreach ($rows as $r) {
            /** @var object{receipt_number: string, method: string, amount_kobo: int|string, tendered_kobo: int|string|null, change_kobo: int|string|null, reference: ?string, alert_seen: ?bool, confirmed_by: ?string, confirmed_at: ?string} $r */
            yield [$r->receipt_number, ['cash' => 'Cash', 'transfer' => 'Transfer', 'pos' => 'POS'][$r->method] ?? $r->method, Csv::naira((int) $r->amount_kobo),
                Csv::naira($r->tendered_kobo === null ? null : (int) $r->tendered_kobo), Csv::naira($r->change_kobo === null ? null : (int) $r->change_kobo),
                $r->reference, $r->alert_seen === null ? '' : ($r->alert_seen ? 'Yes' : 'No'), $r->confirmed_by, $this->time($r->confirmed_at, $zone)];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function prices(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT i.item_code, i.display_name, p.customer_type, p.price_kobo, p.effective_from, u.name AS set_by
            FROM item_prices p JOIN items i ON i.id = p.item_id LEFT JOIN users u ON u.id = p.set_by
            ORDER BY p.effective_from, p.id
            SQL);
        foreach ($rows as $r) {
            /** @var object{item_code: string, display_name: string, customer_type: string, price_kobo: int|string|null, effective_from: string, set_by: ?string} $r */
            yield [$r->item_code, $r->display_name, ucfirst($r->customer_type), $r->price_kobo === null ? 'None' : Csv::naira((int) $r->price_kobo), $this->time($r->effective_from, $zone), $r->set_by];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function costs(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT i.item_code, i.display_name, c.unit_cost_kobo, c.effective_from, u.name AS entered_by, c.undoes_id,
                   EXISTS (SELECT 1 FROM cost_entries x WHERE x.undoes_id = c.id) AS undone
            FROM cost_entries c JOIN items i ON i.id = c.item_id LEFT JOIN users u ON u.id = c.entered_by
            ORDER BY c.effective_from, c.id
            SQL);
        foreach ($rows as $r) {
            /** @var object{item_code: string, display_name: string, unit_cost_kobo: int|string|null, effective_from: string, entered_by: ?string, undoes_id: ?string, undone: bool} $r */
            yield [$r->item_code, $r->display_name, Csv::naira($r->unit_cost_kobo === null ? null : (int) $r->unit_cost_kobo), $this->time($r->effective_from, $zone), $r->entered_by,
                $r->undoes_id === null ? 'No' : 'Yes', $r->undone ? 'Yes' : 'No'];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function shifts(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT t.code, s.business_day, s.opened_at, u.name AS opened_by, s.opening_float_kobo, s.status, s.closed_at
            FROM shifts s JOIN drawers d ON d.id = s.drawer_id JOIN terminals t ON t.id = d.terminal_id LEFT JOIN users u ON u.id = s.opened_by
            ORDER BY s.opened_at, t.code
            SQL);
        $status = ['open' => 'Open', 'closed' => 'Closed', 'auto_closed' => 'Closed by itself, not counted'];
        foreach ($rows as $r) {
            /** @var object{code: string, business_day: string, opened_at: string, opened_by: ?string, opening_float_kobo: int|string|null, status: string, closed_at: ?string} $r */
            yield [$r->code, $r->business_day, $this->time($r->opened_at, $zone), $r->opened_by, Csv::naira($r->opening_float_kobo === null ? null : (int) $r->opening_float_kobo),
                $status[$r->status] ?? $r->status, $this->time($r->closed_at, $zone)];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function drawer(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT e.at, t.code, e.type, e.amount_kobo, e.reason, s.receipt_number, u.name AS who, a.name AS approver
            FROM cash_events e JOIN drawers d ON d.id = e.drawer_id JOIN terminals t ON t.id = d.terminal_id
            LEFT JOIN sales s ON s.id = e.sale_id LEFT JOIN users u ON u.id = e.actor_id LEFT JOIN users a ON a.id = e.approved_by
            ORDER BY e.at, e.id
            SQL);
        foreach ($rows as $r) {
            /** @var object{at: string, code: string, type: string, amount_kobo: int|string|null, reason: ?string, receipt_number: ?string, who: ?string, approver: ?string} $r */
            yield [$this->time($r->at, $zone), $r->code, ucfirst(str_replace('_', ' ', $r->type)), Csv::naira($r->amount_kobo === null ? null : (int) $r->amount_kobo),
                $r->reason, $r->receipt_number, $r->who, $r->approver];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function flagRows(string $zone): Generator
    {
        foreach ($this->flags->list(Period::named('all', $zone), true) as $f) {
            yield [$this->time($f['at'], $zone), $f['label'], $f['title'], $f['detail'], Csv::naira($f['amount_kobo']), Csv::naira($f['cost_kobo'] ?? null),
                $f['who'], $f['approver'], $f['terminal'], $f['receipt_number'], $f['seen_by'], $this->time($f['seen_at'], $zone)];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function people(string $zone): Generator
    {
        foreach (DB::table('users')->orderBy('name')->select(['name', 'role', 'created_at', 'deactivated_at'])->cursor() as $u) {
            /** @var object{name: string, role: string, created_at: ?string, deactivated_at: ?string} $u */
            yield [$u->name, ucfirst($u->role), $u->deactivated_at === null ? 'Yes' : 'No', $this->time($u->created_at, $zone), $this->time($u->deactivated_at, $zone)];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function audit(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT a.recorded_at, coalesce(t.code, 'Branch') AS chain, a.seq, u.name AS who, a.device, a.action, a.subject_type, a.subject_id,
                   a.redacted_before, a.redacted_after, x.before AS sensitive_before, x.after AS sensitive_after, a.hash
            FROM audit_log a LEFT JOIN terminals t ON t.id = a.terminal_id LEFT JOIN users u ON u.id = a.actor_id LEFT JOIN audit_sensitive x ON x.audit_id = a.id
            ORDER BY a.recorded_at, a.chain_id, a.seq
            SQL);
        foreach ($rows as $r) {
            /** @var object{recorded_at: string, chain: string, seq: int|string, who: ?string, device: ?string, action: string, subject_type: ?string, subject_id: ?string, redacted_before: ?string, redacted_after: ?string, sensitive_before: ?string, sensitive_after: ?string, hash: string} $r */
            yield [$this->time($r->recorded_at, $zone), $r->chain, (int) $r->seq, $r->who, $r->device, $r->action, $r->subject_type, $r->subject_id === null ? null : trim($r->subject_id),
                $r->redacted_before, $r->redacted_after, $r->sensitive_before, $r->sensitive_after, $r->hash];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function pilotEvents(string $zone): Generator
    {
        foreach (DB::table('pilot_events as e')->join('users as u', 'u.id', '=', 'e.actor_id')->orderBy('e.seq')->select(['e.at', 'e.kind', 'e.first_day', 'e.decision', 'e.note', 'u.name'])->cursor() as $e) {
            /** @var object{at: string, kind: string, first_day: ?string, decision: ?string, note: ?string, name: string} $e */
            yield [$this->time($e->at, $zone), $e->kind === 'started' ? 'Started the 14 days' : 'Decided', $e->first_day, $e->decision === null ? '' : ucfirst($e->decision), $e->note, $e->name];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function notebook(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT n.business_day, n.method, n.amount_kobo, u.name, n.entered_at,
                   n.seq = (SELECT max(x.seq) FROM notebook_totals x WHERE x.business_day = n.business_day AND x.method = n.method) AS newest
            FROM notebook_totals n JOIN users u ON u.id = n.entered_by ORDER BY n.seq
            SQL);
        foreach ($rows as $r) {
            /** @var object{business_day: string, method: string, amount_kobo: int|string, name: string, entered_at: string, newest: bool} $r */
            yield [$r->business_day, ['cash' => 'Cash', 'transfer' => 'Transfer', 'pos' => 'POS'][$r->method] ?? $r->method, Csv::naira((int) $r->amount_kobo), $r->name,
                $this->time($r->entered_at, $zone), $r->newest ? 'Yes' : 'No, corrected later'];
        }
    }

    /** @return Generator<int, list<string|int|null>> */
    private function traces(string $zone): Generator
    {
        $rows = DB::cursor(<<<'SQL'
            SELECT t.business_day, t.method, t.amount_kobo, t.side, t.what, t.receipt_number, u.name, t.entered_at,
                   EXISTS (SELECT 1 FROM pilot_traces w WHERE w.withdraws_id = t.id) AS withdrawn
            FROM pilot_traces t JOIN users u ON u.id = t.entered_by WHERE t.withdraws_id IS NULL ORDER BY t.seq
            SQL);
        foreach ($rows as $r) {
            /** @var object{business_day: string, method: string, amount_kobo: int|string, side: string, what: string, receipt_number: ?string, name: string, entered_at: string, withdrawn: bool} $r */
            yield [$r->business_day, ['cash' => 'Cash', 'transfer' => 'Transfer', 'pos' => 'POS'][$r->method] ?? $r->method, Csv::naira((int) $r->amount_kobo),
                $r->side === 'notebook' ? 'The notebook' : 'The app', $r->what, $r->receipt_number, $r->name, $this->time($r->entered_at, $zone), $r->withdrawn ? 'Yes' : 'No'];
        }
    }
}

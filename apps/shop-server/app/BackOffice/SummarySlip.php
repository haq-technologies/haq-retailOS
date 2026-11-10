<?php

declare(strict_types=1);

namespace App\BackOffice;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Roots;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Money;
use App\Printing\EscPos;
use App\Printing\PrinterConnection;
use App\Printing\PrinterUnavailable;
use App\Printing\Printing;
use App\Printing\Receipt;
use App\Reports\DailySummary;
use App\Reports\Flags;
use Carbon\CarbonImmutable;

/**
 * The daily summary printed on a counter's 80 mm printer (spec sections 10 and 12) [F14, D18; wireframe
 * DailySummary]. Printing it is audited, then a root is made, so the audit code on the slip covers the
 * whole day including the printing. The slip carries no profit or cost amounts.
 */
final readonly class SummarySlip
{
    public function __construct(
        private DailySummary $summary,
        private Printing $printing,
        private PrinterConnection $printer,
        private AuditLog $audit,
        private Roots $roots,
    ) {}

    /** Prints the day's summary on the counter's printer. Returns the audit code printed. */
    public function print(string $day, Terminal $counter, User $actor): string
    {
        $host = $counter->printer_host;
        if ($counter->kind !== 'counter' || $host === null || $host === '') {
            throw new PrinterUnavailable("{$counter->name} has no printer set.");
        }
        $this->audit->record(new AuditEntry(action: 'summary.printed', actorId: $actor->id, terminalId: $counter->id, subjectType: 'branch', after: ['business_day' => $day]));
        $root = $this->roots->create('daily_summary');
        $slip = self::blocks($this->summary->forDay($day, false), $this->printing->text(), $actor->name, $root['hash']);
        $this->printer->send($host, $counter->printer_port ?? 9100, EscPos::render($slip));

        return self::code($root['hash']);
    }

    /** The audit code as the slip prints it: the root hash's first 32 hex digits, in groups of four. */
    public static function code(string $hash): string
    {
        return strtoupper(implode(' ', str_split(substr($hash, 0, 32), 4)));
    }

    /**
     * @param  array{day: string, opened_at: ?string, counters: list<string>, sales: array{count: int, total_kobo: int, by_method: list<array{method: string, sales: int, amount_kobo: int}>, voids: array{count: int, total_kobo: int}, returns: array{count: int, total_kobo: int}}, transfers_waiting: array{count: int, amount_kobo: int}, buckets: array<string, mixed>, flags: array<string, int>, root: mixed}  $s
     * @param  array{shop_name: ?string, address: ?string, phone: ?string, footer: ?string, currency: string, timezone: string}  $text
     */
    public static function blocks(array $s, array $text, string $printedBy, string $rootHash): Receipt
    {
        $zone = $text['timezone'];
        $b = [['title', $text['shop_name'] ?? 'HAQ Retail OS']];
        foreach ([$text['address'], $text['phone']] as $line) {
            if ($line !== null && $line !== '') {
                $b[] = ['centre', $line];
            }
        }
        $b[] = ['rule'];
        $b[] = ['title', 'DAILY SUMMARY'];
        $b[] = ['pair', 'Business day', CarbonImmutable::parse($s['day'], $zone)->format('D j M Y')];
        if ($s['opened_at'] !== null) {
            $b[] = ['pair', 'Opened', CarbonImmutable::parse($s['opened_at'])->setTimezone($zone)->format('H:i')];
        }
        $b[] = ['pair', 'Printed', CarbonImmutable::now($zone)->format('H:i').' by '.$printedBy];
        $b[] = ['pair', 'Counters', $s['counters'] === [] ? 'none' : implode(', ', $s['counters'])];
        $b[] = ['rule'];

        $b[] = ['pair', 'SALES '.$s['sales']['count'], $text['currency'].Money::plain($s['sales']['total_kobo']), true];
        // Voids and returns are already taken off the total and the methods below (D68).
        foreach (['voids' => 'Voids', 'returns' => 'Returns'] as $key => $label) {
            if ($s['sales'][$key]['count'] > 0) {
                $b[] = ['pair', $label.' '.$s['sales'][$key]['count'], Money::plain($s['sales'][$key]['total_kobo'])];
            }
        }
        $names = ['cash' => 'Cash', 'transfer' => 'Transfer', 'pos' => 'POS'];
        foreach ($s['sales']['by_method'] as $m) {
            $b[] = ['pair', str_pad($names[$m['method']] ?? $m['method'], 12).str_pad((string) $m['sales'], 4, ' ', STR_PAD_LEFT), Money::plain($m['amount_kobo'])];
        }
        $b[] = ['pair', 'Transfers still waiting', (string) $s['transfers_waiting']['count']];
        $b[] = ['rule'];

        $b[] = ['pair', 'REVENUE BY COST', '', true];
        foreach (['confirmed' => 'Confirmed at sale', 'estimated' => 'Estimated', 'pending' => 'Waiting for cost'] as $key => $label) {
            /** @var array{revenue_kobo: int, percent: int} $bucket */
            $bucket = $s['buckets'][$key];
            $b[] = ['pair', str_pad($label, 18).str_pad($bucket['percent'].'%', 4, ' ', STR_PAD_LEFT), Money::plain($bucket['revenue_kobo'])];
        }
        $b[] = ['rule'];

        $b[] = ['pair', 'FLAGS TODAY', '', true];
        foreach (Flags::KINDS as $kind => $label) {
            $n = $s['flags'][$kind] ?? 0;
            if ($n > 0 || in_array($kind, DailySummary::SLIP_FLAGS, true)) {
                $b[] = ['pair', $label, (string) $n];
            }
        }
        $b[] = ['rule'];

        $b[] = ['pair', 'AUDIT CODE', '', true];
        $code = self::code($rootHash);
        $b[] = ['centre', substr($code, 0, 19)];
        $b[] = ['centre', substr($code, 20)];
        foreach (explode("\n", wordwrap('Keep this slip. If the day\'s records are ever changed, they will no longer match this code.', Receipt::WIDTH)) as $line) {
            $b[] = ['line', $line];
        }
        $b[] = ['rule'];
        $b[] = ['centre', 'HAQ Retail OS'];

        return new Receipt($b);
    }
}

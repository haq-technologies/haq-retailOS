<?php

declare(strict_types=1);

namespace App\Printing;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Audit\Roots;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Money;
use App\Shifts\Drawer;
use Carbon\CarbonImmutable;

/**
 * The Z-report: a drawer's shift on paper when the day is closed (spec section 9) [F19, S11; D68]. Printing it
 * is audited and a root is made, so the audit code on the slip covers the whole shift, as on the daily summary.
 *
 * @phpstan-import-type Figures from Drawer
 */
final readonly class ZReport
{
    public function __construct(
        private Drawer $drawer,
        private Printing $printing,
        private PrinterConnection $printer,
        private AuditLog $audit,
        private Roots $roots,
    ) {}

    /** Prints a closed shift's Z-report on the counter's printer. Returns the audit code printed. */
    public function print(string $shiftId, Terminal $counter, User $actor): string
    {
        $host = $counter->printer_host;
        if ($host === null || $host === '') {
            throw new PrinterUnavailable("{$counter->name} has no printer set.");
        }
        $this->audit->record(new AuditEntry(action: 'shift.z_report_printed', actorId: $actor->id, terminalId: $counter->id, subjectType: 'shift', subjectId: $shiftId));
        $root = $this->roots->create('z_report');
        $code = strtoupper(implode(' ', str_split(substr($root['hash'], 0, 32), 4)));
        $this->printer->send($host, $counter->printer_port ?? 9100, EscPos::render(self::blocks($this->drawer->figures($shiftId), $this->printing->text(), $actor->name, $code)));

        return $code;
    }

    /**
     * @param  Figures  $f
     * @param  array{shop_name: ?string, address: ?string, phone: ?string, footer: ?string, currency: string, timezone: string}  $text
     */
    public static function blocks(array $f, array $text, string $printedBy, string $code): Receipt
    {
        $zone = $text['timezone'];
        $time = fn (?string $at) => $at === null ? '' : CarbonImmutable::parse($at)->setTimezone($zone)->format('j M H:i');
        $money = fn (int $kobo) => Money::plain($kobo);
        $b = [['title', $text['shop_name'] ?? 'HAQ Retail OS'], ['rule'], ['title', 'Z-REPORT'],
            ['pair', 'Drawer', $f['drawer']],
            ['pair', 'Business day', CarbonImmutable::parse($f['business_day'], $zone)->format('D j M Y')],
            ['pair', 'Opened', $time($f['opened_at'])],
            ['pair', 'Closed', $time($f['closed_at']).($f['closed_by'] === null ? '' : ' by '.$f['closed_by'])],
            ['pair', 'Printed', CarbonImmutable::now($zone)->format('H:i').' by '.$printedBy],
            ['rule'],
            ['pair', 'SALES '.$f['sales']['count'], $text['currency'].$money($f['sales']['total_kobo']), true],
            ['pair', 'Voids '.$f['voids']['count'], $money($f['voids']['total_kobo'])],
            ['pair', 'Returns '.$f['returns']['count'], $money($f['returns']['total_kobo'])],
            ['pair', 'Cash, after refunds', $money($f['by_method']['cash'])],
            ['pair', 'Transfer, after refunds', $money($f['by_method']['transfer'])],
            ['pair', 'POS, after refunds', $money($f['by_method']['pos'])],
            ['rule'],
            ['pair', 'CASH IN THE DRAWER', '', true],
            ['pair', 'Opening float', $money($f['opening_float_kobo'])],
            ['pair', '+ Cash taken', $money($f['by_method']['cash'])],
            ['pair', '+ Top-ups', $money($f['top_up_kobo'])],
            ['pair', '- Paid-outs', $money($f['paid_out_kobo'])],
            ['pair', '- Drops to the safe', $money($f['drop_kobo'])],
            ['pair', 'Expected', $text['currency'].$money($f['expected_cash_kobo']), true],
        ];
        if ($f['counted_cash_kobo'] !== null && $f['variance_kobo'] !== null) {
            $v = $f['variance_kobo'];
            $b[] = ['pair', 'Counted', $text['currency'].$money($f['counted_cash_kobo']), true];
            $b[] = ['pair', $v === 0 ? 'Difference' : ($v > 0 ? 'Over by' : 'Short by'), $money(abs($v)), true];
            if ($f['flagged']) {
                $b[] = ['centre', 'The difference is flagged for the owner.'];
            }
        } else {
            $b[] = ['centre', 'Not counted: the shift closed by itself.'];
        }
        $b[] = ['rule'];
        $b[] = ['pair', 'Audit code', ''];
        $b[] = ['centre', $code];
        $b[] = ['centre', 'Signature: ________________'];

        return new Receipt($b);
    }
}

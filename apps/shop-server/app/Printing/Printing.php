<?php

declare(strict_types=1);

namespace App\Printing;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Sales\Sales;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Server printing (spec sections 4 and 7) [F5, F12, R3, S9, S21]. The print job was committed with the
 * sale; this sends it. A fitted drawer opens with a cash sale's receipt, and every opening is logged.
 * A job that cannot print stays visible at its counter as a pending receipt until it prints. A reprint
 * reuses the original number, is stamped DUPLICATE and is logged.
 */
final readonly class Printing
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private PrinterConnection $printer,
        private Settings $settings,
        private AuditLog $audit,
    ) {}

    /**
     * Prints one job. Returns true when it is done (printed, or nothing to retry), false to try again.
     *
     * The job is marked as sending, and committed, before the printer is sent anything; the send itself runs
     * outside any transaction. A later attempt that finds a job still sending cannot know whether that paper
     * came out, so it prints a copy stamped DUPLICATE and never opens the drawer a second time (D63).
     */
    public function print(string $printJobId): bool
    {
        /** @var array{job: object{id: string, sale_id: string, terminal_id: string, kind: string, status: string, attempts: int, created_at: string}, attempts: int, host: string, port: int}|bool $claim */
        $claim = DB::transaction(function () use ($printJobId): array|bool {
            /** @var object{id: string, sale_id: string, terminal_id: string, kind: string, status: string, attempts: int, created_at: string}|null $job */
            $job = DB::table('print_jobs')->where('id', $printJobId)->lockForUpdate()->first();
            if ($job === null || $job->status === 'printed') {
                return true;
            }
            if ($job->status === 'sending') {
                $this->markUncertain($job->id, $job->terminal_id);
            }
            $terminal = Terminal::query()->findOrFail($job->terminal_id);
            $attempts = (int) $job->attempts + 1;
            $host = $terminal->printer_host;
            if ($host === null || $host === '') {
                DB::table('print_jobs')->where('id', $job->id)->update(['attempts' => $attempts, 'status' => 'failed']);

                return true; // Nothing to retry until a printer is set; the counter shows it as pending.
            }
            DB::table('print_jobs')->where('id', $job->id)->update(['attempts' => $attempts, 'status' => 'sending']);

            return ['job' => $job, 'attempts' => $attempts, 'host' => $host, 'port' => $terminal->printer_port ?? 9100];
        });
        if (is_bool($claim)) {
            return $claim;
        }

        $job = $claim['job'];
        $terminal = Terminal::query()->findOrFail($job->terminal_id);
        $sale = Sales::summary($job->sale_id);
        // An earlier attempt may have printed it: this copy is stamped, and the drawer stays shut.
        $uncertain = $job->kind === 'receipt' && $this->isUncertain($job->id);
        $duplicate = null;
        if ($job->kind === 'duplicate') {
            $by = DB::table('audit_log')->where('action', 'print.reprint_requested')->where('subject_id', $job->id)->value('actor_id');
            $name = is_string($by) ? DB::table('users')->where('id', $by)->value('name') : null;
            $duplicate = ['at' => CarbonImmutable::parse($job->created_at), 'by' => is_string($name) ? $name : 'staff'];
        } elseif ($uncertain) {
            $duplicate = ['at' => CarbonImmutable::now(), 'by' => 'the server'];
        }
        $receipt = Receipt::forSale($sale, $terminal->name, $this->text(), $duplicate);
        $openDrawer = $job->kind === 'receipt' && ! $uncertain && $this->drawerFitted($terminal)
            && in_array('cash', array_column($sale['payments'], 'method'), true);

        try {
            $this->printer->send($claim['host'], $claim['port'], EscPos::render($receipt, $openDrawer));
        } catch (PrinterUnavailable $e) {
            $final = $claim['attempts'] >= self::MAX_ATTEMPTS;
            DB::transaction(function () use ($job, $terminal, $sale, $final, $e): void {
                DB::table('print_jobs')->where('id', $job->id)->update(['status' => $final ? 'failed' : 'pending']);
                if ($final) {
                    $this->audit->record(new AuditEntry(action: 'print.failed', terminalId: $terminal->id, subjectType: 'print_job', subjectId: $job->id,
                        after: ['receipt_number' => $sale['receipt_number'], 'reason' => $e->getMessage()]));
                }
            });

            return $final;
        }

        DB::transaction(function () use ($job, $terminal, $sale, $openDrawer, $uncertain): void {
            DB::table('print_jobs')->where('id', $job->id)->update(['status' => 'printed']);
            if ($openDrawer) {
                $this->logOpening($terminal, 'sale_open', null, $job->sale_id, null);
            }
            $this->audit->record(new AuditEntry(action: $job->kind === 'duplicate' || $uncertain ? 'print.duplicate_printed' : 'print.printed',
                terminalId: $terminal->id, subjectType: 'print_job', subjectId: $job->id, after: ['receipt_number' => $sale['receipt_number']]));
        });

        return true;
    }

    /**
     * Records that a job's last send never reported back, so nobody knows whether it printed (D63). Once
     * recorded, every later print of that receipt is a stamped copy and leaves the drawer shut.
     */
    public function markUncertain(string $printJobId, string $terminalId): void
    {
        if ($this->isUncertain($printJobId)) {
            return;
        }
        $this->audit->record(new AuditEntry(action: 'print.outcome_unknown', terminalId: $terminalId, subjectType: 'print_job', subjectId: $printJobId));
    }

    private function isUncertain(string $printJobId): bool
    {
        return DB::table('audit_log')->where('action', 'print.outcome_unknown')->where('subject_id', $printJobId)->exists();
    }

    /** A reprint: the original number, stamped DUPLICATE, logged [S9]. Returns the new print job's ID. */
    public function reprint(string $saleId, Terminal $terminal, User $actor): string
    {
        return DB::transaction(function () use ($saleId, $terminal, $actor): string {
            $id = Ulid::generate();
            DB::table('print_jobs')->insert([
                'id' => $id, 'sale_id' => $saleId, 'terminal_id' => $terminal->id, 'kind' => 'duplicate',
                'status' => 'pending', 'attempts' => 0, 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
            ]);
            $receipt = DB::table('sales')->where('id', $saleId)->value('receipt_number');
            $this->audit->record(new AuditEntry(action: 'print.reprint_requested', actorId: $actor->id, terminalId: $terminal->id,
                subjectType: 'print_job', subjectId: $id, after: ['receipt_number' => $receipt, 'sale_id' => $saleId]));

            return $id;
        });
    }

    /** "No sale": opens a fitted drawer with a reason, and logs it [F12, S21]. */
    public function noSale(Terminal $terminal, User $actor, string $reason): void
    {
        if (! $this->drawerFitted($terminal)) {
            throw new PrinterUnavailable('This counter has no cash drawer.');
        }
        $host = $terminal->printer_host;
        if ($host === null || $host === '') {
            throw new PrinterUnavailable('This counter has no printer set, so its drawer cannot open.');
        }
        $this->printer->send($host, $terminal->printer_port ?? 9100, EscPos::drawerKick());
        DB::transaction(fn () => $this->logOpening($terminal, 'no_sale_open', $reason, null, $actor->id));
    }

    /** "Opened with key" after a drawer did not open: the sale is done; the opening is logged [S21]. */
    public function openedWithKey(Terminal $terminal, User $actor, ?string $saleId): void
    {
        if (! $this->drawerFitted($terminal)) {
            throw new PrinterUnavailable('This counter has no cash drawer.');
        }
        DB::transaction(fn () => $this->logOpening($terminal, 'key_open', null, $saleId, $actor->id));
    }

    public function status(Terminal $terminal): string
    {
        $host = $terminal->printer_host;

        return $host !== null && $host !== '' ? $this->printer->status($host, $terminal->printer_port ?? 9100) : 'not_set';
    }

    private function logOpening(Terminal $terminal, string $type, ?string $reason, ?string $saleId, ?string $actorId): void
    {
        $drawer = DB::table('drawers')->where('terminal_id', $terminal->id)->value('id');
        $shift = is_string($drawer) ? DB::table('shifts')->where('drawer_id', $drawer)->where('status', 'open')->value('id') : null;
        if (! is_string($drawer)) {
            return;
        }
        DB::table('cash_events')->insert([
            'id' => Ulid::generate(), 'shift_id' => is_string($shift) ? $shift : null, 'drawer_id' => $drawer, 'type' => $type,
            'reason' => $reason, 'sale_id' => $saleId, 'actor_id' => $actorId, 'at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
        ]);
        $this->audit->record(new AuditEntry(action: "drawer.{$type}", actorId: $actorId, terminalId: $terminal->id, subjectType: 'drawer', subjectId: $drawer,
            after: ['reason' => $reason, 'sale_id' => $saleId]));
    }

    /**
     * Opens a fitted drawer for cash taken out or put in beside a sale (a paid-out, a drop, a top-up), which the
     * drawer log already records (D68). Returns opened, not_opened (the printer did not answer) or none.
     */
    public function openFor(Terminal $terminal): string
    {
        $host = $terminal->printer_host;
        if (! $this->drawerFitted($terminal) || $host === null || $host === '') {
            return 'none';
        }
        try {
            $this->printer->send($host, $terminal->printer_port ?? 9100, EscPos::drawerKick());

            return 'opened';
        } catch (PrinterUnavailable) {
            return 'not_opened';
        }
    }

    private function drawerFitted(Terminal $terminal): bool
    {
        return (bool) DB::table('drawers')->where('terminal_id', $terminal->id)->value('fitted');
    }

    /**
     * The receipt text: the shop's header and footer, the currency mark and the timezone (D14, D31). The
     * daily summary slip prints the same header.
     *
     * @return array{shop_name: ?string, address: ?string, phone: ?string, footer: ?string, currency: string, timezone: string}
     */
    public function text(): array
    {
        $string = fn (string $key): ?string => is_string($v = $this->settings->get($key)) ? $v : null;

        return [
            'shop_name' => $string('receipt.shop_name'),
            'address' => $string('receipt.address'),
            'phone' => $string('receipt.phone'),
            'footer' => $string('receipt.footer'),
            'currency' => match ($string('receipt.currency') ?? 'NGN') {
                'N' => 'N', '₦' => '₦', default => 'NGN '
            },
            'timezone' => $string('business.timezone') ?? 'Africa/Lagos',
        ];
    }
}

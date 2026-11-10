<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\BackOffice\SummarySlip;
use App\Cost\CostAccess;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Printing\PrinterUnavailable;
use App\Reports\CatalogueHealth;
use App\Reports\Csv;
use App\Reports\DailySummary;
use App\Reports\Dashboard;
use App\Reports\DataExport;
use App\Reports\Exports;
use App\Reports\Period;
use App\Reports\Speed;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reports (spec section 12): catalogue health, the daily summary on screen and printed, and the CSV exports
 * of items and sales. The owner sees profit on screen; the printed slip and the exports for anyone who may
 * not see cost carry none [F10, D18].
 */
final class ReportsController
{
    use ActsForUser;

    public function health(Request $request, CatalogueHealth $health): JsonResponse
    {
        $this->allowed($request, 'catalogue');

        return response()->json($health->now($this->zone()));
    }

    public function summary(Request $request, DailySummary $summary, Dashboard $dashboard, CostAccess $cost): JsonResponse
    {
        $actor = $this->allowed($request, 'summary');
        $day = $this->day($request, $dashboard);
        $data = $summary->forDay($day, $cost->canSee($actor));
        $printers = Terminal::query()->where('kind', 'counter')->whereNotNull('printer_host')->where('printer_host', '!=', '')->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Terminal $t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name])->all();

        return response()->json([...$data, 'today' => $dashboard->today(), 'audit_code' => $data['root'] === null ? null : SummarySlip::code($data['root']['hash']), 'printers' => $printers,
            // On screen only: each cashier's speed from the counter's own timing (check A1) [S8, D47].
            'speed' => Speed::forDay($day)]);
    }

    public function print(Request $request, SummarySlip $slip, Dashboard $dashboard): JsonResponse
    {
        $actor = $this->allowed($request, 'summary');
        $day = $this->day($request, $dashboard);
        $id = $request->input('terminal_id');
        abort_unless(Ulid::isValid($id), 422, 'Choose the counter whose printer prints it.');
        $counter = Terminal::query()->whereKey(is_string($id) ? $id : '')->first();
        abort_if($counter === null, 422, 'Choose the counter whose printer prints it.');
        try {
            $code = $slip->print($day, $counter, $actor);
        } catch (PrinterUnavailable $e) {
            return response()->json(['message' => "The printer on {$counter->name} did not print it: {$e->getMessage()} Check it is on and has paper, then try again."], 503);
        }

        return response()->json(['ok' => true, 'audit_code' => $code]);
    }

    public function items(Request $request, CostAccess $cost): Response
    {
        $actor = $this->allowed($request, 'catalogue');
        $withCost = $cost->canSee($actor);

        return Csv::download('items-'.CarbonImmutable::now($this->zone())->format('Y-m-d').'.csv', Exports::itemsHeader($withCost), Exports::items($withCost, $this->zone()));
    }

    public function sales(Request $request, CostAccess $cost, Dashboard $dashboard): Response
    {
        $actor = $this->allowed($request, 'summary');
        $withCost = $cost->canSee($actor);
        $name = $request->query('period');
        $period = match (true) {
            $name === 'day' => Period::day($this->day($request, $dashboard), $this->zone()),
            is_string($name) && in_array($name, Period::NAMES, true) => Period::named($name, $this->zone()),
            default => Period::named('week', $this->zone()),
        };
        $label = $period->name === 'day' ? $this->day($request, $dashboard) : $period->name.'-'.CarbonImmutable::now($this->zone())->format('Y-m-d');

        return Csv::download("sales-{$label}.csv", Exports::salesHeader($withCost), Exports::sales($period, $withCost, $this->zone()));
    }

    /**
     * The shop's whole record for the owner to keep (D57): one ZIP of CSV files, cost included. Made fresh
     * each time, and deleted from the server once sent.
     */
    public function shop(Request $request, DataExport $export, AuditLog $audit): BinaryFileResponse
    {
        $actor = $this->allowed($request, 'export');
        $path = DataExport::temporaryPath();
        $made = $export->write($path, $actor->name);
        $audit->record(new AuditEntry(action: 'export.downloaded', actorId: $actor->id, subjectType: 'shop',
            after: ['files' => count($made['files']), 'sales' => $made['files']['receipts.csv']['rows'] ?? 0]));

        return response()->download($path, $export->filename(), ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store'])->deleteFileAfterSend();
    }

    private function day(Request $request, Dashboard $dashboard): string
    {
        $day = $request->input('day');

        return is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : $dashboard->today();
    }

    private function zone(): string
    {
        $zone = app(Settings::class)->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }
}

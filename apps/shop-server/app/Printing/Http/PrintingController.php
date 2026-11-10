<?php

declare(strict_types=1);

namespace App\Printing\Http;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Printing\PrinterUnavailable;
use App\Printing\Printing;
use App\Printing\PrintReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The counter's printing: printer status, pending receipts, retry, reprint and the drawer.
 */
final class PrintingController
{
    public function status(Request $request, Printing $printing): JsonResponse
    {
        return response()->json(['status' => $printing->status($this->terminal($request))]);
    }

    /** Receipts this counter has not printed yet: offered as "Print pending receipt" [F5]. */
    public function pending(Request $request): JsonResponse
    {
        $jobs = DB::table('print_jobs as j')->join('sales as s', 's.id', '=', 'j.sale_id')
            ->where('j.terminal_id', $this->terminal($request)->id)->whereIn('j.status', ['pending', 'sending', 'failed'])
            ->where('j.created_at', '<', now()->subSeconds(5)->format('Y-m-d H:i:s.uP'))
            ->orderBy('j.created_at')->get(['j.id', 'j.kind', 'j.status', 'j.attempts', 's.receipt_number', 's.id as sale_id']);

        return response()->json(['print_jobs' => $jobs->map(fn (object $j) => [
            'id' => $j->id, 'kind' => $j->kind, 'status' => $j->status, 'attempts' => (int) $j->attempts,
            'receipt_number' => $j->receipt_number, 'sale_id' => $j->sale_id,
        ])->all()]);
    }

    public function retry(Request $request, string $printJob, Printing $printing): JsonResponse
    {
        $terminal = $this->terminal($request);
        $updated = DB::transaction(function () use ($printJob, $terminal, $printing): int {
            $status = DB::table('print_jobs')->where('id', $printJob)->where('terminal_id', $terminal->id)->lockForUpdate()->value('status');
            if (! in_array($status, ['pending', 'sending', 'failed'], true)) {
                return 0;
            }
            // A send that never reported back may have printed: the retry prints a stamped copy (D63).
            if ($status === 'sending') {
                $printing->markUncertain($printJob, $terminal->id);
            }

            return DB::table('print_jobs')->where('id', $printJob)->update(['status' => 'pending']);
        });
        if ($updated === 0) {
            return response()->json(['message' => 'That receipt has already printed.'], 409);
        }
        PrintReceipt::dispatch($printJob);

        return response()->json(['queued' => true]);
    }

    public function reprint(Request $request, string $sale, Printing $printing): JsonResponse
    {
        $terminal = $this->terminal($request);
        if (! DB::table('sales')->where('id', $sale)->where('branch_id', $terminal->branch_id)->exists()) {
            return response()->json(['message' => 'There is no such sale at this branch.'], 404);
        }
        $job = $printing->reprint($sale, $terminal, $this->actor($request));
        PrintReceipt::dispatch($job);

        return response()->json(['print_job' => $job]);
    }

    public function noSale(Request $request, Printing $printing): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:2', 'max:200']])['reason'];
        try {
            $printing->noSale($this->terminal($request), $this->actor($request), $reason);
        } catch (PrinterUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['opened' => true]);
    }

    public function keyOpen(Request $request, Printing $printing): JsonResponse
    {
        $sale = $request->validate(['sale_id' => ['nullable', 'string', 'size:26']])['sale_id'] ?? null;
        try {
            $printing->openedWithKey($this->terminal($request), $this->actor($request), is_string($sale) ? $sale : null);
        } catch (PrinterUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['logged' => true]);
    }

    private function terminal(Request $request): Terminal
    {
        $terminal = $request->attributes->get('terminal');

        return $terminal instanceof Terminal ? $terminal : throw new LogicException('The EnrolledTerminal middleware must run first.');
    }

    private function actor(Request $request): User
    {
        $user = $request->attributes->get('acting_user');

        return $user instanceof User ? $user : throw new LogicException('The ActingUser middleware must run first.');
    }
}

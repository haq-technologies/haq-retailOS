<?php

declare(strict_types=1);

namespace App\Sales\Http;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\ApprovalNeeded;
use App\Sales\Finish;
use App\Sales\JournalNeeded;
use App\Sales\PriceNeedsCheck;
use App\Sales\SaleChanged;
use App\Sales\SaleRefused;
use App\Sales\SaleRequest;
use App\Sales\Sales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SaleController
{
    public function finish(Request $request, Finish $finish): JsonResponse
    {
        $terminal = $request->attributes->get('terminal');
        $cashier = $request->attributes->get('acting_user');
        if (! $terminal instanceof Terminal || ! $cashier instanceof User) {
            throw new LogicException('The EnrolledTerminal and ActingUser middleware must run first.');
        }

        try {
            /** @var array<string, mixed> $body */
            $body = $request->json()->all();
            $sale = $finish->finish($terminal, $cashier, SaleRequest::fromArray($body));
        } catch (SaleChanged $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (JournalNeeded $e) {
            return response()->json(['message' => $e->getMessage(), 'journal_needed' => true], 409);
        } catch (ApprovalNeeded $e) {
            return response()->json(['message' => $e->getMessage(), 'approval' => $e->toArray()], 422);
        } catch (PriceNeedsCheck $e) {
            return response()->json(['message' => $e->getMessage(), 'check' => ['client_line_id' => $e->clientLineId, 'kind' => $e->check]], 422);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sale' => $sale]);
    }

    /**
     * The sale this counter finished under an idempotency key, if any (spec section 7) [F5, D61]. After a
     * Finish that got no answer, the counter asks here before it lets the cashier change the sale, so a sale
     * the server did commit is never sold a second time under a new key.
     */
    public function byKey(Request $request, string $key): JsonResponse
    {
        $terminal = $request->attributes->get('terminal');
        if (! $terminal instanceof Terminal) {
            throw new LogicException('The EnrolledTerminal middleware must run first.');
        }
        $id = DB::table('sales')->where('branch_id', $terminal->branch_id)->where('terminal_id', $terminal->id)
            ->where('idempotency_key', $key)->value('id');

        return is_string($id)
            ? response()->json(['sale' => [...Sales::summary($id), 'retried' => true]])
            : response()->json(['message' => 'This counter has no sale under that key, so nothing was sold.'], 404);
    }
}

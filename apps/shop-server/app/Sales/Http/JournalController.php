<?php

declare(strict_types=1);

namespace App\Sales\Http;

use App\Organisation\Models\Terminal;
use App\Sales\Journal;
use App\Sales\SaleRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * The counter reports its journal (spec sections 4 and 9) [F1, S14]: at start, when it reconnects, and
 * when Finish says the server was restored. It needs only the enrolled device, so a counter waiting at the
 * name tiles reports too.
 */
final class JournalController
{
    public function report(Request $request, Journal $journal): JsonResponse
    {
        $terminal = $request->attributes->get('terminal');
        if (! $terminal instanceof Terminal) {
            throw new LogicException('The EnrolledTerminal middleware must run first.');
        }
        if (! $terminal->canSell()) {
            return response()->json(['ok' => true, 'epoch' => null, 'missing' => 0, 'raised_to' => null]);
        }

        try {
            /** @var array<string, mixed> $body */
            $body = $request->json()->all();

            return response()->json($journal->report($terminal, $body));
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

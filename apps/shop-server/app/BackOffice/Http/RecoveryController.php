<?php

declare(strict_types=1);

namespace App\BackOffice\Http;

use App\Sales\BackEntries;
use App\Sales\BackEntryNeedsItems;
use App\Sales\LostWindow;
use App\Sales\SaleRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lost-window screen (spec section 9; wireframe LostWindow) [F1]: managers and the owner see the sales
 * the counters' journals hold and the restored data lacks, and re-enter each one as a back entry.
 */
final class RecoveryController
{
    use ActsForUser;

    public function index(Request $request): JsonResponse
    {
        $this->allowed($request, 'recovery');

        return response()->json(LostWindow::view());
    }

    public function reEnter(Request $request, string $lost, BackEntries $backEntries): JsonResponse
    {
        $actor = $this->allowed($request, 'recovery');
        $data = $request->validate(['items' => ['sometimes', 'array'], 'items.*' => ['string', 'size:26']]);
        /** @var array<int|string, string> $chosen */
        $chosen = $data['items'] ?? [];
        $replacements = [];
        foreach ($chosen as $line => $itemId) {
            // The screen numbers lines from 1.
            if (is_numeric($line) && (int) $line >= 1) {
                $replacements[(int) $line - 1] = strtolower($itemId);
            }
        }

        try {
            $result = $backEntries->reEnter(strtolower($lost), $actor, $replacements);
        } catch (BackEntryNeedsItems $e) {
            return response()->json(['message' => $e->getMessage(), 'lines' => $e->lines], 422);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['already'] ? 200 : 201);
    }
}

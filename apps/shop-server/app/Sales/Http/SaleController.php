<?php

declare(strict_types=1);

namespace App\Sales\Http;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use App\Sales\SaleChanged;
use App\Sales\SaleRefused;
use App\Sales\SaleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sale' => $sale]);
    }
}

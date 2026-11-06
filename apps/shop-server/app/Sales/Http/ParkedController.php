<?php

declare(strict_types=1);

namespace App\Sales\Http;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\ApprovalNeeded;
use App\Sales\Parked;
use App\Sales\SaleRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * Held sales and Awaiting transfer at the counter [F12, S2].
 */
final class ParkedController
{
    public function index(Request $request, Parked $parked): JsonResponse
    {
        return response()->json(['parked' => $parked->open($this->terminal($request)), 'discard_limit_kobo' => $parked->limitKobo()]);
    }

    public function store(Request $request, Parked $parked): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:hold,awaiting_transfer'],
            'label' => ['nullable', 'string', 'max:60'],
            'payload' => ['required', 'array'],
            'amount_kobo' => ['required', 'integer', 'min:0'],
        ]);

        return $this->guard(function () use ($request, $parked, $data): JsonResponse {
            /** @var array<string, mixed> $payload */
            $payload = $data['payload'];
            $id = $parked->park($this->terminal($request), $this->actor($request), (string) $data['kind'],
                isset($data['label']) ? (string) $data['label'] : null, $payload, (int) $data['amount_kobo']);

            return response()->json(['id' => $id], 201);
        });
    }

    public function resume(Request $request, string $parkedSale, Parked $parked): JsonResponse
    {
        return $this->guard(fn () => response()->json($parked->resume($parkedSale, $this->terminal($request), $this->actor($request))));
    }

    public function discard(Request $request, string $parkedSale, Parked $parked): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'approval_token' => ['nullable', 'string', 'max:128']]);

        return $this->guard(function () use ($request, $parkedSale, $parked, $data): JsonResponse {
            $parked->discard($parkedSale, $this->terminal($request), $this->actor($request), (string) $data['reason'],
                isset($data['approval_token']) ? (string) $data['approval_token'] : null);

            return response()->json(['discarded' => true]);
        });
    }

    /** @param callable(): JsonResponse $action */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ApprovalNeeded $e) {
            return response()->json(['message' => $e->getMessage(), 'approval' => ['action' => $e->action->value, 'subject_id' => $e->subjectId]], 403);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function terminal(Request $request): Terminal
    {
        $terminal = $request->attributes->get('terminal');
        if (! $terminal instanceof Terminal) {
            throw new LogicException('The EnrolledTerminal middleware must run first.');
        }

        return $terminal;
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('acting_user');
        if (! $actor instanceof User) {
            throw new LogicException('The ActingUser middleware must run first.');
        }

        return $actor;
    }
}

<?php

declare(strict_types=1);

namespace App\Sales\Http;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\ApprovalNeeded;
use App\Sales\JournalNeeded;
use App\Sales\Reversals;
use App\Sales\SaleRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * The counter's Returns screen (spec section 9) [F7; D68]: find a sale by its receipt number, void it, or
 * return some of it.
 */
final class ReversalController
{
    public function find(Request $request, Reversals $reversals, string $number): JsonResponse
    {
        return $this->answer(fn () => ['sale' => $reversals->find($this->terminal($request), $number)]);
    }

    public function void(Request $request, Reversals $reversals): JsonResponse
    {
        $data = $this->valid($request, [
            'sale_id' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'max:200'],
            'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'], 'approval_token' => ['nullable', 'string', 'max:64'],
        ]);
        /** @var array{sale_id: string, reason: string, request_id: string, approval_token?: ?string} $data */

        return $this->answer(fn () => ['sale' => $reversals->void($this->terminal($request), $this->actor($request), strtolower($data['sale_id']),
            $data['reason'], $data['request_id'], $data['approval_token'] ?? null)]);
    }

    public function return(Request $request, Reversals $reversals): JsonResponse
    {
        $data = $this->valid($request, [
            'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'receipt_number' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['nullable', 'string', 'size:26'],
            'lines.*.item_id' => ['nullable', 'string', 'size:26'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.unit_price_kobo' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'reason' => ['required', 'string'], 'disposition' => ['required', 'string'], 'refund_method' => ['required', 'string'],
            'refund_reference' => ['nullable', 'string', 'max:60'], 'approval_token' => ['nullable', 'string', 'max:64'],
        ]);
        /** @var array{request_id: string, receipt_number?: ?string, lines: list<array{line_id?: ?string, item_id?: ?string, qty: int|string, unit_price_kobo?: int|string|null}>, reason: string, disposition: string, refund_method: string, refund_reference?: ?string, approval_token?: ?string} $data */
        $lines = $data['lines'];
        $reference = trim($data['refund_reference'] ?? '');

        return $this->answer(fn () => ['sale' => $reversals->return($this->terminal($request), $this->actor($request), [
            'request_id' => $data['request_id'],
            'receipt_number' => $data['receipt_number'] ?? null,
            'lines' => array_map(fn (array $l) => array_filter([
                'line_id' => isset($l['line_id']) ? strtolower($l['line_id']) : null,
                'item_id' => isset($l['item_id']) ? strtolower($l['item_id']) : null,
                'qty' => (int) $l['qty'],
                'unit_price_kobo' => isset($l['unit_price_kobo']) ? (int) $l['unit_price_kobo'] : null,
            ], fn (mixed $v) => $v !== null), $lines),
            'reason' => $data['reason'], 'disposition' => $data['disposition'], 'refund_method' => $data['refund_method'],
            'refund_reference' => $reference === '' ? null : $reference,
            'approval_token' => $data['approval_token'] ?? null,
        ])]);
    }

    /**
     * @param  Closure(): array<string, mixed>  $work
     */
    private function answer(Closure $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (JournalNeeded $e) {
            return response()->json(['message' => $e->getMessage(), 'journal_needed' => true], 409);
        } catch (ApprovalNeeded $e) {
            return response()->json(['message' => $e->getMessage(), 'approval' => $e->toArray()], 422);
        } catch (SaleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private function valid(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            abort(response()->json(['message' => (string) $validator->errors()->first()], 422));
        }

        /** @var array<string, mixed> */
        return $validator->validated();
    }

    private function terminal(Request $request): Terminal
    {
        $terminal = $request->attributes->get('terminal');

        return $terminal instanceof Terminal ? $terminal : throw new LogicException('The EnrolledTerminal middleware must run first.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('acting_user');

        return $actor instanceof User ? $actor : throw new LogicException('The ActingUser middleware must run first.');
    }
}

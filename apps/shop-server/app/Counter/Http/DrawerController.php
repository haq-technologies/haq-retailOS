<?php

declare(strict_types=1);

namespace App\Counter\Http;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Printing\PrinterUnavailable;
use App\Printing\Printing;
use App\Printing\ZReport;
use App\Shifts\Drawer;
use App\Shifts\DrawerRefused;
use App\Shifts\NeedsApproval;
use App\Shifts\Shifts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * The counter's drawer (spec section 9) [F19; D68]: cash out of or into it beside a sale, and closing the day
 * with a blind count and the Z-report.
 */
final class DrawerController
{
    public function cash(Request $request, Drawer $drawer, Printing $printing): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'in:paid_out,drop,top_up'], 'amount_kobo' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'reason' => ['required', 'string', 'max:200'], 'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'approval_token' => ['nullable', 'string', 'max:64'],
        ], ['reason.required' => 'Say what the cash is for.']);
        if ($validator->fails()) {
            return response()->json(['message' => (string) $validator->errors()->first()], 422);
        }
        /** @var array{type: string, amount_kobo: int|string, reason: string, request_id: string, approval_token?: ?string} $data */
        $data = $validator->validated();
        $terminal = $this->terminal($request);
        try {
            $event = $drawer->record($terminal, $this->actor($request), $data['type'], (int) $data['amount_kobo'], $data['reason'], $data['request_id'], $data['approval_token'] ?? null);
        } catch (NeedsApproval $e) {
            return response()->json(['message' => $e->getMessage(), 'approval' => $e->toArray()], 422);
        } catch (DrawerRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([...$event, 'drawer' => $event['already'] ? 'none' : $printing->openFor($terminal)]);
    }

    /** Whether there is a shift to close, without what it should hold: the count is blind. */
    public function status(Request $request, Shifts $shifts, Drawer $drawer): JsonResponse
    {
        $terminal = $this->terminal($request);
        $shift = $shifts->current($terminal);

        return response()->json([
            'open' => $shift === null ? null : ['id' => $shift->id, 'business_day' => $shift->business_day, 'opened_at' => $shift->opened_at],
            'last_closed' => $drawer->lastClosed($terminal),
        ]);
    }

    public function close(Request $request, Drawer $drawer, ZReport $report): JsonResponse
    {
        $counted = $request->input('counted_kobo');
        if (! is_int($counted) && ! (is_string($counted) && ctype_digit($counted))) {
            return response()->json(['message' => 'Type the cash counted in the drawer.'], 422);
        }
        $terminal = $this->terminal($request);
        $actor = $this->actor($request);
        try {
            $figures = $drawer->close($terminal, $actor, (int) $counted);
        } catch (DrawerRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['shift' => $figures, ...$this->printed($report, $figures['shift_id'], $terminal, $actor)]);
    }

    public function print(Request $request, Drawer $drawer, ZReport $report): JsonResponse
    {
        $terminal = $this->terminal($request);
        $shiftId = $drawer->lastClosed($terminal);
        if ($shiftId === null) {
            return response()->json(['message' => 'This counter has no closed shift to print.'], 422);
        }

        return response()->json(['shift' => $drawer->figures($shiftId), ...$this->printed($report, $shiftId, $terminal, $this->actor($request))]);
    }

    /**
     * @return array{printed: bool, audit_code: ?string, print_error: ?string}
     */
    private function printed(ZReport $report, string $shiftId, Terminal $terminal, User $actor): array
    {
        try {
            return ['printed' => true, 'audit_code' => $report->print($shiftId, $terminal, $actor), 'print_error' => null];
        } catch (PrinterUnavailable $e) {
            return ['printed' => false, 'audit_code' => null, 'print_error' => "The Z-report did not print: {$e->getMessage()} Check the printer, then print it again."];
        }
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

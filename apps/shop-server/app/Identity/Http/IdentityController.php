<?php

declare(strict_types=1);

namespace App\Identity\Http;

use App\Identity\ApprovalAction;
use App\Identity\ApprovalRefused;
use App\Identity\Approvals;
use App\Identity\Enrolment;
use App\Identity\Models\User;
use App\Identity\SignIn;
use App\Organisation\Models\Terminal;
use App\Platform\Http\Page;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use LogicException;

/**
 * The counter's sign-in: enrolment, name tiles and the PIN pad, the acting person, and manager approvals.
 * Every prop and answer is an explicit array; no model reaches the page (spec section 4) [F10].
 */
final class IdentityController
{
    public function enrolForm(): Response
    {
        return Page::render('identity/enrol');
    }

    public function enrol(Request $request, Enrolment $enrolment): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $cookie = $enrolment->redeem((string) $code);
        if ($cookie === null) {
            return back()->withErrors(['code' => 'That code is not valid, or it has expired. Ask HAQ support for a new one.']);
        }

        return redirect()->route('identity.tiles')->withCookie(cookie(
            Enrolment::COOKIE, $cookie, minutes: 60 * 24 * 365 * 10, secure: $request->isSecure(), httpOnly: true, sameSite: 'strict',
        ));
    }

    public function tiles(Request $request, Settings $settings): Response
    {
        $terminal = $this->terminal($request);

        return Page::render('identity/sign-in', [
            'terminal' => ['code' => $terminal->code, 'name' => $terminal->name],
            'tiles' => User::query()->active()->whereNotNull('pin_hash')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'pinLength' => $u->role->pinLength()])
                ->all(),
            'idleLockMinutes' => $settings->get('identity.idle_lock_minutes'),
        ]);
    }

    public function pin(Request $request, SignIn $signIn): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'string', 'size:26'], 'pin' => ['required', 'string', 'max:6']]);
        $result = $signIn->attempt($this->terminal($request), (string) $data['user_id'], (string) $data['pin']);

        if (! $result->succeeded() || $result->user === null) {
            return response()->json([
                'outcome' => $result->outcome,
                'message' => $result->message(),
                'wait_seconds' => $result->waitSeconds,
            ], match ($result->outcome) {
                'locked' => 423,
                'backoff' => 429,
                default => 422,
            });
        }

        return response()->json([
            'outcome' => 'signed_in',
            'token' => $result->token,
            'expires_at' => $result->expires?->toIso8601String(),
            'user' => ['id' => $result->user->id, 'name' => $result->user->name, 'role' => $result->user->role->value],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->value]]);
    }

    public function approve(Request $request, Approvals $approvals): JsonResponse
    {
        $data = $request->validate([
            'approver_id' => ['required', 'string', 'size:26'],
            'pin' => ['required', 'string', 'max:6'],
            'action' => ['required', Rule::enum(ApprovalAction::class)],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'item_id' => ['nullable', 'string', 'size:26'],
            'price_kobo' => ['nullable', 'integer', 'min:0'],
        ]);
        $user = $this->actingUser($request);

        try {
            $approval = $approvals->grant(
                $this->terminal($request), $user->id, (string) $data['approver_id'], (string) $data['pin'],
                ApprovalAction::from((string) $data['action']),
                isset($data['subject_id']) ? (string) $data['subject_id'] : null,
                isset($data['item_id']) ? (string) $data['item_id'] : null,
                isset($data['price_kobo']) ? (int) $data['price_kobo'] : null,
            );
        } catch (ApprovalRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['approval_token' => $approval['token'], 'expires_at' => $approval['expires']->toIso8601String()]);
    }

    private function terminal(Request $request): Terminal
    {
        $terminal = $request->attributes->get('terminal');

        return $terminal instanceof Terminal ? $terminal : throw new LogicException('The EnrolledTerminal middleware must run first.');
    }

    private function actingUser(Request $request): User
    {
        $user = $request->attributes->get('acting_user');

        return $user instanceof User ? $user : throw new LogicException('The ActingUser middleware must run first.');
    }
}

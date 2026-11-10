<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Signs a person in at a terminal with their name tile and PIN (spec section 3, mode A) [F8, F9, S6].
 * Every attempt is recorded and audited on the terminal's chain; a success mints the acting-user token.
 */
final readonly class SignIn
{
    public function __construct(
        private Pins $pins,
        private Throttle $throttle,
        private Settings $settings,
        private AuditLog $audit,
    ) {}

    public function attempt(Terminal $terminal, string $userId, string $pin): SignInResult
    {
        $user = User::query()->active()->find($userId);
        if ($user === null) {
            return SignInResult::unavailable();
        }

        return DB::transaction(function () use ($terminal, $user, $pin): SignInResult {
            // One PIN at a time per counter and per person, so guesses sent at once cannot slip past the
            // backoff or the lockout before the earlier ones are counted [F8].
            $this->throttle->serialise($terminal->id, $user->id);
            $wait = $this->throttle->terminalWaitSeconds($terminal->id);
            if ($wait > 0) {
                return SignInResult::terminalBackoff($wait);
            }
            $lockedUntil = $this->throttle->lockedUntil($user->id);
            if ($lockedUntil !== null) {
                return SignInResult::locked($lockedUntil, $this->timezone());
            }

            $ok = $this->pins->check($pin, $user->pin_hash);
            $this->throttle->record($terminal->id, $user->id, 'sign_in', $ok);

            if (! $ok) {
                $this->audit->record(new AuditEntry(action: 'identity.sign_in_failed', terminalId: $terminal->id, subjectType: 'user', subjectId: $user->id));
                $lockedUntil = $this->throttle->lockedUntil($user->id);
                if ($lockedUntil !== null) {
                    $this->audit->record(new AuditEntry(action: 'identity.locked_out', terminalId: $terminal->id, subjectType: 'user', subjectId: $user->id,
                        after: ['until' => $lockedUntil->toIso8601String()]));

                    return SignInResult::locked($lockedUntil, $this->timezone());
                }

                return SignInResult::wrongPin();
            }

            $this->audit->record(new AuditEntry(action: 'identity.signed_in', actorId: $user->id, terminalId: $terminal->id,
                after: ['role' => $user->role->value]));
            $expires = $this->expiry($user->role);

            return SignInResult::signedIn($user, ActingToken::mint($user->id, $terminal->id, $user->role, $expires), $expires);
        });
    }

    private function timezone(): string
    {
        $zone = $this->settings->get('business.timezone');

        return is_string($zone) ? $zone : 'Africa/Lagos';
    }

    /**
     * A token lives as long as the idle lock (spec section 3) [S6]; a manager's or owner's as long as the
     * elevated-session limit. With the idle lock off, a token lasts a working day.
     */
    public function expiry(Role $role): CarbonImmutable
    {
        $key = $role->isElevated() ? 'identity.elevated_idle_minutes' : 'identity.idle_lock_minutes';
        $minutes = $this->settings->get($key);
        $minutes = is_int($minutes) && $minutes > 0 ? $minutes : 12 * 60;

        return CarbonImmutable::now()->addMinutes($minutes);
    }
}

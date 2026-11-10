<?php

declare(strict_types=1);

namespace App\Identity;

use App\Platform\Ulid;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Wrong-PIN protection (spec section 3) [F8]. Five wrong PINs in a row lock that person for a cooldown.
 * Separately, a terminal backs off exponentially after ten wrong PINs in ten minutes across all names,
 * so guessing across names is slowed too. Both are read from the append-only sign_in_attempts table,
 * which also feeds the owner dashboard.
 */
final readonly class Throttle
{
    public function __construct(private Settings $settings) {}

    /**
     * Takes this counter's and this person's PIN locks until the transaction ends, so the checks below and
     * the attempt's record happen as one step. Always the counter first, then the person, so two locks
     * never wait on each other in a circle. Call inside a transaction.
     */
    public function serialise(string $terminalId, string $userId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pin-terminal:'.$terminalId]);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pin-user:'.$userId]);
    }

    public function record(string $terminalId, ?string $userId, string $kind, bool $succeeded): void
    {
        DB::table('sign_in_attempts')->insert([
            'id' => Ulid::generate(),
            'user_id' => $userId,
            'terminal_id' => $terminalId,
            'kind' => $kind,
            'succeeded' => $succeeded,
            // To the microsecond, so attempts within one second keep their order.
            'attempted_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /** When the person's lockout ends, or null if they are not locked out. */
    public function lockedUntil(string $userId): ?CarbonImmutable
    {
        $limit = $this->int('identity.lockout_attempts');
        $attempts = DB::table('sign_in_attempts')->where('user_id', $userId)
            ->orderByDesc('attempted_at')->limit($limit)->get(['succeeded', 'attempted_at']);

        if ($attempts->count() < $limit || $attempts->contains(fn (object $a) => (bool) $a->succeeded)) {
            return null;
        }

        $until = CarbonImmutable::parse((string) $attempts->first()?->attempted_at)->addMinutes($this->int('identity.lockout_minutes'));

        return $until->isFuture() ? $until : null;
    }

    /** How many seconds this terminal must wait before the next PIN, or 0. */
    public function terminalWaitSeconds(string $terminalId): int
    {
        $threshold = $this->int('identity.terminal_backoff_failures');
        $since = CarbonImmutable::now()->subMinutes($this->int('identity.terminal_backoff_window_minutes'));

        $failures = DB::table('sign_in_attempts')->where('terminal_id', $terminalId)
            ->where('succeeded', false)->where('attempted_at', '>=', $since)
            ->orderByDesc('attempted_at')->pluck('attempted_at');

        if ($failures->count() < $threshold) {
            return 0;
        }

        // 1 second after the tenth failure, doubling with each one after it, at most 5 minutes.
        $delay = min(300, 2 ** ($failures->count() - $threshold));
        $last = $failures->first();
        $until = CarbonImmutable::parse(is_string($last) ? $last : 'now')->addSeconds($delay);

        return $until->isFuture() ? (int) ceil(CarbonImmutable::now()->diffInSeconds($until)) : 0;
    }

    private function int(string $key): int
    {
        $value = $this->settings->get($key);

        return is_int($value) ? $value : (int) (is_scalar($value) ? $value : 0);
    }
}

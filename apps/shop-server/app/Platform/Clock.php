<?php

declare(strict_types=1);

namespace App\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The server clock (spec section 4, Security and clock; check E3) [F15, D45]. A shop server often has no
 * internet time, and a flat motherboard battery sends the clock back to 2000 after a power cut. A date
 * before this version was built, or a jump backward of more than five minutes from the latest time seen,
 * puts the server in unverified-clock mode: selling goes on, every sale is stamped unverified, and the
 * owner is asked to confirm the time. Nothing ever locks because of the clock, and no time is rewritten.
 */
final class Clock
{
    /** The latest time seen while the clock was trusted, kept by the minute's check. */
    public const HIGH_WATER = 'clock.high_water';

    /** A backward step smaller than this is ordinary time-keeping, not a wrong clock. */
    private const SLACK_SECONDS = 300;

    private static ?CarbonImmutable $floor = null;

    /**
     * The earliest the time can be: the date of this version's newest migration, since the version cannot
     * run before it was written.
     */
    public static function floor(): CarbonImmutable
    {
        if (self::$floor === null) {
            $newest = '2026_11_06';
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                if (preg_match('/^(\d{4}_\d{2}_\d{2})_/', basename($file), $m) === 1 && $m[1] > $newest) {
                    $newest = $m[1];
                }
            }
            self::$floor = CarbonImmutable::createFromFormat('Y_m_d H:i:s', "{$newest} 00:00:00", 'UTC') ?: CarbonImmutable::now();
        }

        return self::$floor;
    }

    /**
     * The clock's state now: trusted, or unverified with why and since when.
     *
     * @return array{state: string, reason: ?string, since: ?string, server_time: string, confirmed_at: ?string, confirmed_by: ?string}
     */
    public function state(): array
    {
        $now = CarbonImmutable::now();
        /** @var object{kind: string, reason: ?string, server_time: string, actor: ?string}|null $last */
        $last = DB::table('clock_events as c')->leftJoin('users as u', 'u.id', '=', 'c.actor_id')
            ->orderByDesc('c.seq')->first(['c.kind', 'c.reason', 'c.server_time', 'u.name as actor']);
        $base = ['server_time' => $now->toIso8601String(), 'confirmed_at' => null, 'confirmed_by' => null];
        if ($now->lessThan(self::floor())) {
            return [...$base, 'state' => 'unverified', 'reason' => 'before_build', 'since' => $last?->kind === 'unverified' ? $last->server_time : null];
        }
        if ($last?->kind === 'unverified') {
            return [...$base, 'state' => 'unverified', 'reason' => $last->reason, 'since' => $last->server_time];
        }

        return [...$base, 'state' => 'trusted', 'reason' => null, 'since' => null,
            'confirmed_at' => $last?->server_time, 'confirmed_by' => $last?->actor];
    }

    /** What a sale is stamped with (spec section 5, sales.clock_state). */
    public function stamp(): string
    {
        return $this->state()['state'] === 'trusted' ? 'trusted' : 'unverified';
    }

    /**
     * The minute's check: notes a clock before the build date or one that jumped back, and otherwise moves
     * the latest trusted time on. Returns the reason when the clock has just become unverified.
     */
    public function check(): ?string
    {
        $now = CarbonImmutable::now();
        $high = Cache::get(self::HIGH_WATER);
        $high = is_string($high) ? CarbonImmutable::parse($high) : null;
        $state = $this->state();

        $reason = null;
        if ($now->lessThan(self::floor())) {
            $reason = 'before_build';
        } elseif ($high !== null && $now->lessThan($high->subSeconds(self::SLACK_SECONDS))) {
            $reason = 'jumped_back';
        }

        if ($reason !== null) {
            $recorded = DB::table('clock_events')->orderByDesc('seq')->value('kind');
            if ($recorded !== 'unverified') {
                $this->record(['kind' => 'unverified', 'reason' => $reason, 'server_time' => $now->format('Y-m-d H:i:s.uP'),
                    'last_trusted' => $high?->format('Y-m-d H:i:s.uP')]);

                return $reason;
            }

            return null;
        }
        if ($state['state'] === 'trusted' && ($high === null || $now->greaterThan($high))) {
            Cache::forever(self::HIGH_WATER, $now->toIso8601String());
        }

        return null;
    }

    /**
     * The owner confirms the server's time is right: sales are stamped trusted again from now, and the
     * latest trusted time starts from here, so a clock put back on purpose is not a jump.
     */
    public function confirm(string $actorId): void
    {
        $now = CarbonImmutable::now();
        if ($now->lessThan(self::floor())) {
            throw new RuntimeException('The server\'s clock says '.$now->setTimezone('Africa/Lagos')->format('j F Y').', before this version of HAQ Retail OS was made, so it cannot be right. HAQ support sets the clock; selling goes on meanwhile.');
        }
        $this->record(['kind' => 'confirmed', 'server_time' => $now->format('Y-m-d H:i:s.uP'), 'actor_id' => $actorId]);
        Cache::forever(self::HIGH_WATER, $now->toIso8601String());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function record(array $row): void
    {
        DB::transaction(function () use ($row): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('clock_events', 0))");
            $seq = DB::table('clock_events')->max('seq');
            DB::table('clock_events')->insert([...$row, 'id' => Ulid::generate(), 'seq' => (is_numeric($seq) ? (int) $seq : 0) + 1]);
        });
    }
}

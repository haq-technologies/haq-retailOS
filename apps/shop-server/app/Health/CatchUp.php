<?php

declare(strict_types=1);

namespace App\Health;

use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Catch-up scheduling (spec section 4; check E4) [F24, D45]: a nightly job runs once a day in the night
 * window, or at the next quiet moment (no sale for ten minutes) if the server was off at night, so a shop
 * that switches its server off still gets every check. The backup service does the same for the backups
 * and the weekly test restore (docker/postgres/haq-backup.sh).
 */
final readonly class CatchUp
{
    /** The night window, in the shop's time: from 01:00 for five hours, as the backups. */
    private const WINDOW_FROM = 1;

    private const WINDOW_HOURS = 5;

    public function __construct(private Settings $settings) {}

    /** Whether a job whose last run is kept under this cache key is due now. */
    public function due(string $key): bool
    {
        $last = Health::lastRun($key);
        if ($last !== null && CarbonImmutable::parse($last['checked_at'])->greaterThan(CarbonImmutable::now()->subHours(23))) {
            return false;
        }

        return $this->quiet();
    }

    /** The night window, or ten minutes with no sale. */
    public function quiet(): bool
    {
        $zone = $this->settings->get('business.timezone');
        $hour = (int) CarbonImmutable::now(is_string($zone) ? $zone : 'Africa/Lagos')->format('G');
        if ($hour >= self::WINDOW_FROM && $hour < self::WINDOW_FROM + self::WINDOW_HOURS) {
            return true;
        }

        return ! DB::table('sales')->where('recorded_at', '>', CarbonImmutable::now()->subMinutes(10)->format('Y-m-d H:i:s.uP'))->exists();
    }
}

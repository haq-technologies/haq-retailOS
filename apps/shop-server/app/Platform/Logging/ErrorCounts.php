<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How many errors the server met in the last 24 hours, for the health page (finding LG6): the errors it
 * reported (including those the throttle kept out of the log, finding LG8), the counter screens that failed
 * and the browser errors that did not stop one (finding LG3). Counted per hour in the cache, so nothing
 * here is a record and the counts expire by themselves.
 */
final class ErrorCounts
{
    /** An error the server reported: a 500, a failed job or task, anything that reached the log. */
    public const ERROR = 'error';

    /** A counter or back-office screen that failed and showed "Something went wrong". */
    public const CRASH = 'crash';

    /** A browser error that did not stop the screen. Shown to HAQ support only. */
    public const BROWSER = 'browser';

    public const KINDS = [self::ERROR, self::CRASH, self::BROWSER];

    private const PREFIX = 'health.errors.';

    /** Counting never stops an error being logged: if the cache cannot be written, the count is lost. */
    public static function add(string $kind): void
    {
        try {
            $now = CarbonImmutable::now('UTC');
            $hour = $now->format('YmdH');
            $expires = $now->addHours(26);
            $key = self::PREFIX.$kind.'.'.$hour;
            Cache::add($key, 0, $expires);
            Cache::increment($key);
            Cache::add(self::PREFIX.'first.'.$kind.'.'.$hour, $now->toIso8601String(), $expires);
        } catch (Throwable) {
            // The database may be the fault being reported.
        }
    }

    /**
     * The last 24 hours, counted by the hour: the current hour and the 23 before it.
     *
     * @return array{error: int, crash: int, browser: int, first_at: ?string}
     */
    public static function lastDay(): array
    {
        $now = CarbonImmutable::now('UTC');
        $keys = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->subHours($i)->format('YmdH');
            foreach (self::KINDS as $kind) {
                $keys[] = self::PREFIX.$kind.'.'.$hour;
                $keys[] = self::PREFIX.'first.'.$kind.'.'.$hour;
            }
        }
        // One read for all of them: the health page and the dashboard ask on every load.
        $values = Cache::many($keys);

        $counts = array_fill_keys(self::KINDS, 0);
        $first = null;
        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->subHours($i)->format('YmdH');
            foreach (self::KINDS as $kind) {
                $n = $values[self::PREFIX.$kind.'.'.$hour] ?? null;
                if (! is_numeric($n) || (int) $n <= 0) {
                    continue;
                }
                $counts[$kind] += (int) $n;
                $at = $values[self::PREFIX.'first.'.$kind.'.'.$hour] ?? null;
                if ($kind !== self::BROWSER && is_string($at) && ($first === null || $at < $first)) {
                    $first = $at;
                }
            }
        }

        return [...$counts, 'first_at' => $first];
    }
}

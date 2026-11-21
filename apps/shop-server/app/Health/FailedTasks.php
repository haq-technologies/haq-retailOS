<?php

declare(strict_types=1);

namespace App\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The scheduled tasks that failed in the last 24 hours (findings LG2, LG6): the scheduler records each
 * failure here as it writes the task's own output to its log (routes/console.php). HAQ support reads them
 * with `haq errors`; the owner sees only that the server had errors.
 */
final class FailedTasks
{
    private const KEY = 'health.failed_tasks';

    /** "audit:root", from the scheduler's "'/usr/local/bin/php' 'artisan' audit:root". */
    public static function name(string $command): string
    {
        return preg_match("/'artisan'\\s+(.+)$/", $command, $m) === 1 ? trim($m[1]) : trim($command);
    }

    /** Never throws: the cache may be the very thing that is failing. */
    public static function record(string $task): void
    {
        try {
            // Two scheduler runs can overlap when one is slow, so the read and the write go under a lock.
            Cache::lock(self::KEY.'.lock', 10)->block(3, function () use ($task): void {
                $now = CarbonImmutable::now('UTC');
                $all = self::recent($now);
                $count = isset($all[$task]) ? $all[$task]['count'] + 1 : 1;
                $all[$task] = ['first_at' => $all[$task]['first_at'] ?? $now->toIso8601String(), 'last_at' => $now->toIso8601String(), 'count' => $count];
                Cache::forever(self::KEY, $all);
            });
        } catch (Throwable) {
            // Lost: the task's failure is still in the scheduler's log.
        }
    }

    /**
     * Newest first.
     *
     * @return list<array{task: string, first_at: string, last_at: string, count: int}>
     */
    public static function lastDay(): array
    {
        $out = [];
        foreach (self::recent(CarbonImmutable::now('UTC')) as $task => $run) {
            $out[] = ['task' => $task, ...$run];
        }
        usort($out, fn (array $a, array $b) => strcmp($b['last_at'], $a['last_at']));

        return $out;
    }

    /**
     * @return array<string, array{first_at: string, last_at: string, count: int}>
     */
    private static function recent(CarbonImmutable $now): array
    {
        $stored = Cache::get(self::KEY);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $task => $run) {
            if (! is_string($task) || ! is_array($run) || ! is_string($run['last_at'] ?? null) || ! is_string($run['first_at'] ?? null) || ! is_int($run['count'] ?? null)) {
                continue;
            }
            // A task that has not failed for a day is forgotten, with its count.
            if (CarbonImmutable::parse($run['last_at'])->lessThan($now->subDay())) {
                continue;
            }
            $out[$task] = ['first_at' => $run['first_at'], 'last_at' => $run['last_at'], 'count' => $run['count']];
        }

        return $out;
    }
}

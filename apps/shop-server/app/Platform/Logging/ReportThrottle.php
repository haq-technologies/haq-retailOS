<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Illuminate\Cache\RateLimiting\Limit;
use Throwable;

/**
 * One fault must not wash out the log (finding LG8). An error is 143 lines with its stack trace, and a fault
 * that fails every counter poll would turn the 50 MB cap over in under an hour, taking the first error with
 * it. So the same error is written in full a few times a minute; every one is still counted for the health
 * page (finding LG6). "The same" is the same class, thrown from the same line, with the same first line of
 * message once its numbers are set aside.
 */
final class ReportThrottle
{
    public const PER_MINUTE = 5;

    public static function for(Throwable $e): Limit
    {
        ErrorCounts::add(ErrorCounts::ERROR);

        return Limit::perMinute(self::PER_MINUTE)->by('report:'.self::key($e));
    }

    public static function key(Throwable $e): string
    {
        $first = strtok($e->getMessage(), "\r\n");

        return $e::class.'|'.$e->getFile().':'.$e->getLine().'|'.preg_replace('/\d+/', '#', $first === false ? '' : $first);
    }
}

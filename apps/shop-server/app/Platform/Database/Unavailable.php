<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Whether an error only means the database is not there yet: starting after a power cut, restarting for
 * an update, or not reachable for a moment (spec section 4, reboot recovery) [D46]. Such a request is
 * answered 503 with Retry-After, so nginx shows "Starting, please wait" and a counter shows Reconnecting,
 * instead of a 500 error.
 */
final class Unavailable
{
    private const SIGNS = [
        'the database system is starting up',
        'the database system is shutting down',
        'the database system is in recovery mode',
        'Connection refused',
        'could not translate host name',
        'Name or service not known',
        'timeout expired',
        'No such file or directory',
        'SQLSTATE[08006]',
        'SQLSTATE[08001]',
        'SQLSTATE[57P03]',
    ];

    public static function is(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (! $cause instanceof QueryException && ! $cause instanceof PDOException) {
                continue;
            }
            $message = $cause->getMessage();
            foreach (self::SIGNS as $sign) {
                if (str_contains($message, $sign)) {
                    return true;
                }
            }
            if ((new LostConnectionDetector)->causedByLostConnection($cause)) {
                return true;
            }
        }

        return false;
    }
}

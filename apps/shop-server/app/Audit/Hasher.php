<?php

declare(strict_types=1);

namespace App\Audit;

use Carbon\CarbonInterface;

/**
 * The one way audit rows and roots are hashed, so the writer and the verifier always agree.
 * Values are hashed as canonical JSON: keys sorted at every level, no whitespace. PostgreSQL's jsonb
 * reorders keys, so sorting makes a value read back from the database hash the same as when written.
 */
final class Hasher
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function hash(array $fields): string
    {
        return hash('sha256', self::canonical($fields));
    }

    public static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Times are hashed in UTC to the microsecond, the precision PostgreSQL keeps. */
    public static function time(CarbonInterface $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sorted(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sorted(...), $value);
    }
}

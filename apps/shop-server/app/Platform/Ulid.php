<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Str;

/**
 * New primary keys (spec section 4). Lowercase, as Laravel's HasUlids writes them, so every key in the
 * database compares the same way whichever code made it.
 */
final class Ulid
{
    public static function generate(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** Whether a value from a request is a key as this app writes them. */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $value) === 1;
    }
}

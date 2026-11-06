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
}

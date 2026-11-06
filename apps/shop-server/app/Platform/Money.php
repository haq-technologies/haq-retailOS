<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Money is integer kobo in data and naira in words (CLAUDE.md): ₦2,500, or ₦2,500.50 when there are kobo.
 */
final class Money
{
    /** Without the sign, as a receipt prints line amounts: 14,000 or 14,000.50. */
    public static function plain(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);
        $rest = $kobo % 100;

        return $sign.number_format(intdiv($kobo, 100)).($rest === 0 ? '' : sprintf('.%02d', $rest));
    }

    public static function naira(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);
        $naira = number_format(intdiv($kobo, 100));
        $rest = $kobo % 100;

        return $sign.'₦'.$naira.($rest === 0 ? '' : sprintf('.%02d', $rest));
    }
}

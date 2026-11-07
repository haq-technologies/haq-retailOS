<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Http\Response;

/**
 * CSV files that open cleanly in Excel (spec section 12): UTF-8 with a byte-order mark, so ₦ and names read
 * right; Windows line endings; every field quoted; money as plain naira amounts with two decimals, so Excel
 * sums them. A cell that starts like a formula (=, +, -, @, tab or carriage return) is prefixed with an
 * apostrophe, so a typed name can never run as a formula in the owner's spreadsheet.
 */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function make(array $header, iterable $rows): string
    {
        $out = "\u{FEFF}".self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): Response
    {
        return new Response(self::make($header, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Kobo as a plain naira amount for a spreadsheet: 2500.00. */
    public static function naira(?int $kobo): string
    {
        if ($kobo === null) {
            return '';
        }
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return $sign.intdiv($kobo, 100).'.'.sprintf('%02d', $kobo % 100);
    }

    /**
     * @param  list<string|int|null>  $fields
     */
    private static function line(array $fields): string
    {
        return implode(',', array_map(function (string|int|null $field): string {
            if ($field === null) {
                return '""';
            }
            if (is_int($field)) {
                return '"'.$field.'"';
            }
            if ($field !== '' && in_array($field[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($field)) {
                $field = "'".$field;
            }

            return '"'.str_replace('"', '""', $field).'"';
        }, $fields))."\r\n";
    }
}

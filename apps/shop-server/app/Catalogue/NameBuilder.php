<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * The one routine that builds every printed name (spec section 6) [F20, C1, FU4]. It follows the
 * category's name order (brand, model or connector pair, category word, specs, unless the shop says it
 * another way). Blank parts are skipped, colour is never part of the name, and a cashier's free text
 * comes last: the name is always the routine's output, never the free text alone. Every sellable
 * category has a word, so a name is never blank and never "Others".
 */
final class NameBuilder
{
    public const WORD = '{word}';

    /**
     * @param  list<string>  $nameOrder  Facet keys and {word}, as the category template lists them.
     * @param  array<string, string|null>  $parts  Each facet's printed value; "No brand" arrives as null.
     */
    public static function build(array $nameOrder, string $word, array $parts, ?string $freeText = null): string
    {
        $pieces = [];
        foreach ($nameOrder as $token) {
            $pieces[] = $token === self::WORD ? $word : ($parts[$token] ?? null);
        }
        $pieces[] = $freeText;

        $kept = array_filter(array_map(fn (?string $p) => $p === null ? '' : trim(preg_replace('/\s+/u', ' ', $p) ?? ''), $pieces), fn (string $p) => $p !== '');

        return implode(' ', $kept);
    }

    /** The form used to compare names and typed text: lowercase, single spaces. */
    public static function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}

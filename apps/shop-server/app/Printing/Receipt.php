<?php

declare(strict_types=1);

namespace App\Printing;

use App\Platform\Money;
use Carbon\CarbonImmutable;

/**
 * The 80 mm receipt, as the signed-off wireframe draws it (spec section 7) [S9, D14]: the shop's header,
 * the receipt number, date, counter and cashier, each line with its colour note, the total, the payments
 * and change, the receipt number as a Code 128 barcode, and the footer. A reprint carries a DUPLICATE band
 * and says when and by whom it was reprinted. Built as blocks, so the printer's ESC/POS and the plain-text
 * preview always show the same thing.
 */
final readonly class Receipt
{
    public const WIDTH = 48;

    /**
     * @param  list<array{0: string, 1?: string, 2?: string, 3?: bool}>  $blocks  [kind, text, right text, bold]
     */
    public function __construct(public array $blocks) {}

    /**
     * @param  array{receipt_number: string, recorded_at: string, cashier: string, total_kobo: int, lines: list<array{name: string, colour: ?string, qty: int, unit_price_kobo: int, line_total_kobo: int}>, payments: list<array{method: string, amount_kobo: int, tendered_kobo: ?int, change_kobo: ?int, reference: ?string}>}  $sale  Sales::summary()
     * @param  array{shop_name: ?string, address: ?string, phone: ?string, footer: ?string, currency: string, timezone: string}  $text
     * @param  array{at: CarbonImmutable, by: string}|null  $duplicate
     */
    public static function forSale(array $sale, string $counterName, array $text, ?array $duplicate = null): self
    {
        $b = [];
        if ($duplicate !== null) {
            $b[] = ['band', 'DUPLICATE'];
        }
        $b[] = ['title', $text['shop_name'] ?? 'HAQ Retail OS'];
        foreach ([$text['address'], $text['phone']] as $line) {
            if ($line !== null && $line !== '') {
                $b[] = ['centre', $line];
            }
        }
        $b[] = ['rule'];
        $when = CarbonImmutable::parse($sale['recorded_at'])->setTimezone($text['timezone']);
        $b[] = ['pair', 'Receipt', $sale['receipt_number']];
        $b[] = ['pair', 'Date', $when->format('j M Y H:i')];
        $b[] = ['pair', 'Counter', $counterName];
        $b[] = ['pair', 'Cashier', $sale['cashier']];
        $b[] = ['rule'];

        foreach ($sale['lines'] as $line) {
            $b[] = ['line', $line['name']];
            if ($line['colour'] !== null) {
                $b[] = ['indent', 'Colour: '.$line['colour']];
            }
            $b[] = ['indentpair', $line['qty'].' x '.Money::plain($line['unit_price_kobo']), Money::plain($line['line_total_kobo'])];
        }
        $b[] = ['rule'];
        $b[] = ['pair', 'TOTAL', $text['currency'].Money::plain($sale['total_kobo']), true];

        foreach ($sale['payments'] as $payment) {
            if ($payment['method'] === 'cash') {
                $b[] = ['pair', 'Cash', Money::plain($payment['tendered_kobo'] ?? $payment['amount_kobo'])];
                if (($payment['change_kobo'] ?? 0) > 0) {
                    $b[] = ['pair', 'Change', Money::plain((int) $payment['change_kobo'])];
                }
            } else {
                $b[] = ['pair', $payment['method'] === 'pos' ? 'POS' : 'Transfer', Money::plain($payment['amount_kobo'])];
                if ($payment['reference'] !== null) {
                    $b[] = ['indent', 'Ref: '.$payment['reference']];
                }
            }
        }
        $b[] = ['rule'];
        $b[] = ['barcode', $sale['receipt_number']];
        $b[] = ['centre', $sale['receipt_number']];
        if ($duplicate !== null) {
            $b[] = ['centre', 'Reprinted '.$duplicate['at']->setTimezone($text['timezone'])->format('j M Y H:i').' by '.$duplicate['by']];
        } elseif ($text['footer'] !== null && $text['footer'] !== '') {
            $b[] = ['centre', $text['footer']];
        }

        return new self($b);
    }

    /** The receipt as plain text, 48 characters wide: the preview, the test view and the stand-in printer's log. */
    public function text(): string
    {
        $out = [];
        foreach ($this->blocks as $block) {
            $kind = $block[0];
            $left = $block[1] ?? '';
            $right = $block[2] ?? '';
            $out[] = match ($kind) {
                'band' => str_pad("*** {$left} ***", self::WIDTH, ' ', STR_PAD_BOTH),
                'title', 'centre' => self::centre($left),
                'rule' => str_repeat('-', self::WIDTH),
                'pair' => self::pair($left, $right),
                'indentpair' => self::pair('  '.$left, $right),
                'line' => $left,
                'indent' => '  '.$left,
                'barcode' => self::centre("|| {$left} ||"),
                default => '',
            };
        }

        return implode("\n", $out)."\n";
    }

    public static function pair(string $left, string $right): string
    {
        $space = self::WIDTH - mb_strlen($left) - mb_strlen($right);

        return $space >= 1 ? $left.str_repeat(' ', $space).$right : $left."\n".str_pad($right, self::WIDTH, ' ', STR_PAD_LEFT);
    }

    private static function centre(string $text): string
    {
        return str_pad($text, self::WIDTH, ' ', STR_PAD_BOTH);
    }
}

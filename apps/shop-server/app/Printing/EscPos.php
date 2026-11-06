<?php

declare(strict_types=1);

namespace App\Printing;

use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer;

/**
 * Turns a receipt into ESC/POS bytes for an 80 mm thermal printer (spec section 4) [R3, S9]: auto-cut, the
 * receipt number as a Code 128 barcode, and, when asked, a pulse that opens the drawer plugged into the
 * printer.
 */
final class EscPos
{
    public static function render(Receipt $receipt, bool $openDrawer = false): string
    {
        $connector = new DummyPrintConnector;
        $printer = new Printer($connector);
        try {
            $printer->initialize();

            foreach ($receipt->blocks as $block) {
                $kind = $block[0];
                $left = $block[1] ?? '';
                $right = $block[2] ?? '';
                $bold = $block[3] ?? false;

                switch ($kind) {
                    case 'band':
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        $printer->setReverseColors(true);
                        $printer->setEmphasis(true);
                        $printer->setTextSize(2, 1);
                        $printer->text(" {$left} \n");
                        $printer->setTextSize(1, 1);
                        $printer->setEmphasis(false);
                        $printer->setReverseColors(false);
                        $printer->feed();
                        break;
                    case 'title':
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        $printer->setEmphasis(true);
                        $printer->text($left."\n");
                        $printer->setEmphasis(false);
                        break;
                    case 'centre':
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        $printer->text($left."\n");
                        break;
                    case 'rule':
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                        $printer->text(str_repeat('-', Receipt::WIDTH)."\n");
                        break;
                    case 'pair':
                    case 'indentpair':
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                        $printer->setEmphasis($bold);
                        $printer->text(Receipt::pair(($kind === 'indentpair' ? '  ' : '').$left, $right)."\n");
                        $printer->setEmphasis(false);
                        break;
                    case 'line':
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                        $printer->text($left."\n");
                        break;
                    case 'indent':
                        $printer->setJustification(Printer::JUSTIFY_LEFT);
                        $printer->text('  '.$left."\n");
                        break;
                    case 'barcode':
                        $printer->setJustification(Printer::JUSTIFY_CENTER);
                        $printer->setBarcodeHeight(64);
                        $printer->barcode('{B'.$left, Printer::BARCODE_CODE128);
                        break;
                }
            }

            $printer->feed(2);
            $printer->cut(Printer::CUT_PARTIAL);
            if ($openDrawer) {
                $printer->pulse();
            }
            $bytes = $connector->getData();
        } finally {
            $printer->close();
        }

        return $bytes;
    }

    /** Opens the drawer and nothing else, for "No sale" [S21]. */
    public static function drawerKick(): string
    {
        $connector = new DummyPrintConnector;
        $printer = new Printer($connector);
        $printer->pulse();
        $bytes = $connector->getData();
        $printer->close();

        return $bytes;
    }
}

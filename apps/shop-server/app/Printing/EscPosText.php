<?php

declare(strict_types=1);

namespace App\Printing;

/**
 * Reads ESC/POS bytes back as text: what a printer would print, without its control codes. The stand-in
 * printer logs receipts with it, and tests check what was sent.
 */
final class EscPosText
{
    public static function decode(string $bytes): string
    {
        $out = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $c = $bytes[$i];
            $o = ord($c);
            if ($o === 0x1B && $i + 1 < $n) { // ESC
                $cmd = $bytes[$i + 1];
                $i += match ($cmd) {
                    '@' => 1,
                    'p' => 4,
                    'd', 'a', 'E', 'M', 't', '!', '-', 'G', 'J', 'e', 'r', 'V', 'U', '{', '3' => 2,
                    default => 2,
                };
                if ($cmd === 'p') {
                    $out .= "[drawer]\n";
                }

                continue;
            }
            if ($o === 0x1D && $i + 1 < $n) { // GS
                $cmd = $bytes[$i + 1];
                if ($cmd === 'V') {
                    $mode = ord($bytes[$i + 2] ?? "\0");
                    $i += $mode >= 0x41 ? 3 : 2;
                    $out .= "[cut]\n";

                    continue;
                }
                if ($cmd === 'k') {
                    $mode = ord($bytes[$i + 2] ?? "\0");
                    if ($mode >= 65) {
                        $length = ord($bytes[$i + 3] ?? "\0");
                        $data = substr($bytes, $i + 4, $length);
                        $out .= '[barcode '.ltrim($data, '{ABC')."]\n";
                        $i += 3 + $length;
                    } else {
                        $end = strpos($bytes, "\0", $i + 3);
                        $out .= '[barcode '.substr($bytes, $i + 3, ($end === false ? $n : $end) - $i - 3)."]\n";
                        $i = $end === false ? $n : $end;
                    }

                    continue;
                }
                $i += match ($cmd) {
                    'L', 'W' => 3, '(' => 2, default => 2
                };

                continue;
            }
            if ($o === 0x10 && $i + 2 < $n) { // DLE EOT n: a status query
                $i += 2;

                continue;
            }
            if ($c === "\n" || $o >= 0x20) {
                $out .= $c;
            }
        }

        return $out;
    }
}

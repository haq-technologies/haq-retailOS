<?php

declare(strict_types=1);

namespace App\Printing;

/**
 * The server prints straight to the printer's port 9100 (spec section 4) [R3]; there is no agent on the
 * counter PC. The status is a real-time query on the same socket (DLE EOT): paper out, cover open.
 */
final class NetworkPrinter implements PrinterConnection
{
    private const TIMEOUT = 3.0;

    public function send(string $host, int $port, string $bytes): void
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, self::TIMEOUT);
        if ($socket === false) {
            throw new PrinterUnavailable("The printer at {$host} does not answer ({$error}).");
        }
        stream_set_timeout($socket, (int) self::TIMEOUT);
        $written = 0;
        $length = strlen($bytes);
        while ($written < $length) {
            $sent = fwrite($socket, substr($bytes, $written));
            if ($sent === false || $sent === 0) {
                fclose($socket);
                throw new PrinterUnavailable("The printer at {$host} stopped taking the receipt.");
            }
            $written += $sent;
        }
        fflush($socket);
        fclose($socket);
    }

    public function status(string $host, int $port): string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 1.5);
        if ($socket === false) {
            return 'offline';
        }
        stream_set_timeout($socket, 1);
        // DLE EOT 4: paper sensor status; DLE EOT 2: offline cause (cover open).
        fwrite($socket, "\x10\x04\x04\x10\x04\x02");
        $answer = fread($socket, 2);
        fclose($socket);
        if ($answer === false || strlen($answer) < 2) {
            return 'ready'; // Some printers do not answer status queries; a written receipt still prints.
        }
        if ((ord($answer[0]) & 0x60) !== 0) {
            return 'paper_out';
        }
        if ((ord($answer[1]) & 0x04) !== 0) {
            return 'cover_open';
        }

        return 'ready';
    }
}

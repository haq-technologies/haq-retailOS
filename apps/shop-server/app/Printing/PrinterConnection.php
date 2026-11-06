<?php

declare(strict_types=1);

namespace App\Printing;

/**
 * How bytes reach a counter's printer.
 */
interface PrinterConnection
{
    /** @throws PrinterUnavailable */
    public function send(string $host, int $port, string $bytes): void;

    /** ready, paper_out, cover_open or offline. */
    public function status(string $host, int $port): string;
}

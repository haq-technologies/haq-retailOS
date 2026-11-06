<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Printing\EscPosText;
use App\Printing\PrinterConnection;
use App\Printing\PrinterUnavailable;

/**
 * A printer for tests: it keeps what it was sent, and can be switched off.
 */
final class RecordingPrinter implements PrinterConnection
{
    /** @var list<array{host: string, port: int, bytes: string}> */
    public array $sent = [];

    public bool $online = true;

    public function send(string $host, int $port, string $bytes): void
    {
        if (! $this->online) {
            throw new PrinterUnavailable("The printer at {$host} does not answer (test).");
        }
        $this->sent[] = ['host' => $host, 'port' => $port, 'bytes' => $bytes];
    }

    public function status(string $host, int $port): string
    {
        return $this->online ? 'ready' : 'offline';
    }

    public function lastText(): string
    {
        $last = end($this->sent);

        return $last === false ? '' : EscPosText::decode($last['bytes']);
    }
}

<?php

declare(strict_types=1);

use App\Printing\EscPos;
use App\Printing\NetworkPrinter;
use App\Printing\PrinterUnavailable;
use App\Printing\Receipt;
use Symfony\Component\Process\Process;

/*
 * The real network path (spec section 4) [R3]: bytes over TCP to a printer's port, and the status query
 * on the same socket. The stand-in printer runs as its own process.
 */

beforeEach(function () {
    $this->port = 19_100 + random_int(0, 500);
    $this->log = storage_path('framework/testing/printer-'.$this->port.'.log');
    @unlink($this->log);
    $this->process = new Process([PHP_BINARY, 'artisan', 'printing:fake-printer', "--port={$this->port}", "--log={$this->log}"], base_path());
    $this->process->start();
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && @stream_socket_client("tcp://127.0.0.1:{$this->port}", $e, $s, 0.2) === false) {
        usleep(100_000);
    }
});

afterEach(function () {
    $this->process->stop(0);
    @unlink($this->log);
});

it('sends a receipt to the printer\'s port and reads the printer\'s status', function () {
    $receipt = new Receipt([['title', 'Example Accessories'], ['rule'], ['pair', 'Receipt', 'T01-E1-000042'], ['barcode', 'T01-E1-000042']]);
    $printer = new NetworkPrinter;

    expect($printer->status('127.0.0.1', $this->port))->toBe('ready');
    $printer->send('127.0.0.1', $this->port, EscPos::render($receipt));

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && ! str_contains((string) @file_get_contents($this->log), 'T01-E1-000042')) {
        usleep(100_000);
    }
    expect((string) file_get_contents($this->log))->toContain('Example Accessories')->toContain('[barcode T01-E1-000042]');
});

it('says plainly when no printer answers', function () {
    expect(fn () => (new NetworkPrinter)->send('127.0.0.1', 1, 'x'))->toThrow(PrinterUnavailable::class, 'does not answer');
    expect((new NetworkPrinter)->status('127.0.0.1', 1))->toBe('offline');
});

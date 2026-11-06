<?php

declare(strict_types=1);

namespace App\Printing\Console;

use App\Printing\EscPosText;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * A stand-in network receipt printer for development and staging, until the pilot's printer arrives. It
 * listens like a printer on port 9100, answers status queries as ready, and writes each receipt as text.
 */
#[AsCommand(name: 'printing:fake-printer')]
class FakePrinterCommand extends Command
{
    protected $signature = 'printing:fake-printer {--port=9100} {--log= : Where to write receipts (default: storage/logs/fake-printer.log)}';

    protected $description = 'Run a stand-in network receipt printer that writes receipts as text';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $log = is_string($this->option('log')) && $this->option('log') !== '' ? (string) $this->option('log') : storage_path('logs/fake-printer.log');
        $server = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $error);
        if ($server === false) {
            $this->components->error("Cannot listen on port {$port}: {$error}");

            return self::FAILURE;
        }
        $this->components->info("The stand-in printer listens on port {$port} and writes receipts to {$log}.");

        $running = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$running): void {
            $running = false;
        });
        pcntl_signal(SIGINT, function () use (&$running): void {
            $running = false;
        });

        while ($running) {
            $client = @stream_socket_accept($server, 1);
            if ($client === false) {
                continue;
            }
            stream_set_timeout($client, 2);
            $bytes = '';
            while (! feof($client)) {
                $chunk = fread($client, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes .= $chunk;
                if (str_contains($bytes, "\x10\x04")) {
                    fwrite($client, "\x12\x12"); // ready: paper present, cover closed
                }
            }
            fclose($client);
            $text = EscPosText::decode($bytes);
            if (trim($text) !== '') {
                file_put_contents($log, '=== '.now()->toIso8601String()." ===\n".$text."\n", FILE_APPEND);
                $this->line($text);
            }
        }
        fclose($server);

        return self::SUCCESS;
    }
}

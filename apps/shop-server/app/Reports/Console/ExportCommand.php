<?php

declare(strict_types=1);

namespace App\Reports\Console;

use App\Audit\AuditEntry;
use App\Audit\AuditLog;
use App\Reports\DataExport;
use Illuminate\Console\Command;

/**
 * The owner's data export from the server (D57), for HAQ: when the owner stops, or whenever the shop asks
 * for its data and no back-office device is to hand. "-" writes the ZIP to standard output, so it can be
 * saved straight onto a drive: docker compose exec -T app php artisan shop:export - > shop-data.zip
 */
final class ExportCommand extends Command
{
    protected $signature = 'shop:export {to? : The ZIP file to write (default: storage/app/exports); "-" writes it to standard output}';

    protected $description = 'Export the shop\'s whole record, cost included, as one ZIP of CSV files for the owner';

    public function handle(DataExport $export, AuditLog $audit): int
    {
        $to = $this->argument('to');
        $stdout = $to === '-';
        $path = match (true) {
            $stdout => DataExport::temporaryPath(),
            is_string($to) && $to !== '' => $to,
            default => storage_path('app/exports/'.$export->filename()),
        };
        if (! $stdout && ! is_dir(dirname($path)) && ! mkdir(dirname($path), 0750, true)) {
            $this->error('The folder '.dirname($path).' could not be made.');

            return self::FAILURE;
        }

        $made = $export->write($path, 'HAQ support (shop:export)');
        $audit->record(new AuditEntry(action: 'export.made', subjectType: 'shop', device: 'server',
            after: ['files' => count($made['files']), 'sales' => $made['files']['receipts.csv']['rows'] ?? 0]));

        if ($stdout) {
            $in = fopen($path, 'rb');
            $out = fopen('php://stdout', 'wb');
            if ($in === false || $out === false) {
                return self::FAILURE;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($path);

            return self::SUCCESS;
        }
        $this->info("The shop's data is in {$path} (".count($made['files']).' files, '.($made['files']['receipts.csv']['rows'] ?? 0).' sales).');
        $this->line('Give it to the owner on a drive. It holds cost, so it is for the owner only.');

        return self::SUCCESS;
    }
}

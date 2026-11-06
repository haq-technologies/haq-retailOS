<?php

declare(strict_types=1);

namespace App\Backups\Console;

use App\Backups\Status;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** The backups at a glance, until the health page shows them (spec section 9) [F24]. */
final class StatusCommand extends Command
{
    protected $signature = 'backups:status';

    protected $description = 'Show the last backups and the last verified test restore';

    public function handle(): int
    {
        $status = Status::fromConfig();
        if (! $status->enabled()) {
            $this->components->warn('There is no backup status folder on this machine.');

            return self::FAILURE;
        }
        $backups = $status->read('backup.json');
        $list = is_array($backups['backups'] ?? null) ? $backups['backups'] : [];
        foreach ($list as $b) {
            if (is_array($b) && is_int($b['stop'] ?? null)) {
                $this->line(sprintf('%-8s %-28s %s', is_string($b['type'] ?? null) ? $b['type'] : '', is_string($b['label'] ?? null) ? $b['label'] : '', CarbonImmutable::createFromTimestamp($b['stop'])->toDateTimeString()));
            }
        }
        $last = end($list);
        $stop = is_array($last) && is_int($last['stop'] ?? null) ? $last['stop'] : 0;
        if ($stop === 0 || time() - $stop > 48 * 3600) {
            $this->components->warn('The last backup is older than 48 hours, or there is none.');
        }
        $restore = $status->read('restore.json');
        $this->line('Test restore: '.json_encode($restore));

        return self::SUCCESS;
    }
}

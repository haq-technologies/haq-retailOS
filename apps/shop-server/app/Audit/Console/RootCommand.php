<?php

declare(strict_types=1);

namespace App\Audit\Console;

use App\Audit\Roots;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'audit:root')]
class RootCommand extends Command
{
    protected $signature = 'audit:root {--reason=minute : minute, z_report, daily_summary or restore}';

    protected $description = 'Create an audit root over every chain head (every minute, and at each Z-report and daily summary)';

    public function handle(Roots $roots): int
    {
        $reason = (string) $this->option('reason');
        if (! in_array($reason, Roots::REASONS, true)) {
            $this->components->error('The reason must be one of: '.implode(', ', Roots::REASONS).'.');

            return self::INVALID;
        }
        $root = $roots->create($reason);
        $this->components->info("Audit root {$root['seq']}: {$root['hash']}");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Audit\Console;

use App\Audit\AuditLog;
use App\Audit\Spool;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'audit:ingest-spool')]
class IngestSpoolCommand extends Command
{
    protected $signature = 'audit:ingest-spool';

    protected $description = 'Move failure entries waiting in the local spool into the audit trail';

    public function handle(Spool $spool, AuditLog $log): int
    {
        $count = $spool->ingest($log);
        $this->components->info($count === 0 ? 'The audit spool is empty.' : "Ingested {$count} spooled audit entries.");

        return self::SUCCESS;
    }
}

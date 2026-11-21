<?php

declare(strict_types=1);

namespace App\Health\Console;

use App\Health\SupportAccess;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Records a `sudo haq` command that reaches the shop's records on the audit trail and the owner's flag list
 * (D110, finding DP3). The haq script calls it before the command runs, with who ran it ($SUDO_USER) and
 * from where ($SSH_CLIENT); it is never something a person types.
 */
#[AsCommand(name: 'support:record')]
final class SupportRecordCommand extends Command
{
    protected $signature = 'support:record {what : The haq command, such as export} {--by= : Who ran it} {--from= : The address they came from}';

    protected $description = 'Record a support command on the audit trail (run by haq)';

    public function handle(SupportAccess $support): int
    {
        $by = $this->option('by');
        $from = $this->option('from');
        $support->command((string) $this->argument('what'), is_string($by) ? $by : null, is_string($from) ? $from : null);

        return self::SUCCESS;
    }
}

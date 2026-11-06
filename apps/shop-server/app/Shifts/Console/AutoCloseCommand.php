<?php

declare(strict_types=1);

namespace App\Shifts\Console;

use App\Shifts\Shifts;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shifts:auto-close')]
class AutoCloseCommand extends Command
{
    protected $signature = 'shifts:auto-close';

    protected $description = 'Close, as unclosed, every shift still open four hours after closing time';

    public function handle(Shifts $shifts): int
    {
        $count = $shifts->autoClose();
        $this->components->info($count === 0 ? 'No forgotten shifts.' : "Closed {$count} forgotten shifts as unclosed.");

        return self::SUCCESS;
    }
}

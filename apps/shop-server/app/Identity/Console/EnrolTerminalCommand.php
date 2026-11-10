<?php

declare(strict_types=1);

namespace App\Identity\Console;

use App\Identity\Enrolment;
use App\Organisation\Models\Branch;
use App\Organisation\Models\Terminal;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Issues the one-time code that enrols a counter PC as a terminal (spec section 3) [F9].
 */
#[AsCommand(name: 'shop:enrol-terminal')]
class EnrolTerminalCommand extends Command
{
    protected $signature = 'shop:enrol-terminal {code : The terminal code: a counter such as T01, or a back-office device such as D01}';

    protected $description = 'Make a one-time code that enrols a counter PC as a terminal';

    public function handle(Enrolment $enrolment): int
    {
        $terminal = Terminal::query()->where('branch_id', Branch::local()->id)->where('code', (string) $this->argument('code'))->first();
        if ($terminal === null) {
            $this->components->error("This branch has no terminal {$this->argument('code')}. Add it to the shop profile first.");

            return self::FAILURE;
        }

        $code = $enrolment->issueCode($terminal);
        $this->components->info("On {$terminal->code} ({$terminal->name}), open /enrol in the browser and type the code below.");
        $this->line('It works once, for '.Enrolment::CODE_MINUTES.' minutes.');
        // The code comes last, on its own line, for `haq enrol` to show.
        $this->line($code);

        return self::SUCCESS;
    }
}

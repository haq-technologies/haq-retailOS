<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Organisation\Models\Terminal;
use App\Sales\Journal;
use Tests\Concurrency\Scenario;

/** A counter reports its journal, as it does at start and on reconnecting (D44). */
final class ReportJournal implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = Terminal::query()->findOrFail($args['terminal']);
        /** @var array<string, mixed> $report */
        $report = $args['report'];

        return app(Journal::class)->report($terminal, $report);
    }
}

<?php

declare(strict_types=1);

namespace App\Health\Console;

use App\Health\FailedTasks;
use App\Platform\Logging\ErrorCounts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * For HAQ support (`sudo haq errors`, and in the support file): what the owner's one line "The server had
 * errors" stands for (finding LG6). The errors the server reported and the counter screens that failed in
 * the last 24 hours, the scheduled tasks that failed and the queue's failed jobs. The errors themselves are in
 * the log, found by the reference a cashier reads out.
 */
final class ErrorsCommand extends Command
{
    protected $signature = 'health:errors';

    protected $description = 'Show the last 24 hours of errors, failed tasks and failed jobs, for HAQ support';

    public function handle(): int
    {
        $counts = ErrorCounts::lastDay();
        $this->line('The last 24 hours (UTC):');
        $this->line("  Errors the server logged:     {$counts[ErrorCounts::ERROR]}");
        $this->line("  Screens that failed:          {$counts[ErrorCounts::CRASH]}");
        $this->line("  Browser errors (screen kept): {$counts[ErrorCounts::BROWSER]}");
        $this->line('  First at:                     '.($counts['first_at'] ?? 'none'));

        $tasks = FailedTasks::lastDay();
        $this->newLine();
        $this->line($tasks === [] ? 'No scheduled task failed.' : 'Scheduled tasks that failed (their output is in `haq logs scheduler`):');
        foreach ($tasks as $t) {
            $this->line("  {$t['task']}: {$t['count']} times, last at {$t['last_at']}");
        }

        $since = CarbonImmutable::now('UTC')->subDay();
        $jobs = DB::table('failed_jobs')->where('failed_at', '>=', $since)->orderByDesc('failed_at')->limit(10)->get(['payload', 'exception', 'failed_at']);
        $this->newLine();
        $this->line($jobs->isEmpty() ? 'No queue job failed.' : 'Queue jobs that failed (newest first, at most 10):');
        foreach ($jobs as $job) {
            /** @var object{payload: string, exception: string, failed_at: string} $job */
            $payload = json_decode($job->payload, true);
            $name = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'a job';
            $first = strtok($job->exception, "\r\n");
            $this->line("  {$job->failed_at} {$name}: ".($first === false ? '' : mb_strimwidth($first, 0, 300, '…')));
        }

        return self::SUCCESS;
    }
}

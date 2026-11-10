<?php

declare(strict_types=1);

namespace App\Counter\Console;

use App\Identity\Models\User;
use App\Identity\Role;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Scripted sales for a test install (the G3 software rehearsal, staging, and CI's upgrade-path test): sales
 * across several counters at once, each counter its own process, through Finish and the print queue to the
 * stand-in printer. The sales are real rows that can never be deleted, so this runs only on a rehearsal or
 * test install, which is wiped afterwards. Power the server off while it runs; then `shop:rehearsal-check`
 * shows what survived (D60).
 */
final class RehearseCommand extends Command
{
    protected $signature = 'shop:rehearse
        {--counters=T01,T02 : The counters that sell}
        {--count=300 : Sales in all, shared between the counters}
        {--pace=2 : Seconds between one counter\'s sales}
        {--cashier= : The cashier who sells (default: the first active cashier)}
        {--confirm : Required: these are real sales on this server}';

    protected $description = 'Scripted sales on several counters at once (a rehearsal or test install only)';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->components->error('These are real sales that can never be deleted. Run it only on a rehearsal or test install, with --confirm.');

            return self::INVALID;
        }
        $cashierName = $this->option('cashier');
        $cashier = is_string($cashierName) && $cashierName !== ''
            ? User::query()->active()->where('name', $cashierName)->first()
            : User::query()->active()->where('role', Role::Cashier->value)->orderBy('name')->first();
        if ($cashier === null) {
            $this->components->error('There is no such active cashier. Add one with shop:add-user.');

            return self::FAILURE;
        }

        $counters = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('counters')))));
        $count = max(1, (int) $this->option('count'));
        $each = intdiv($count, max(1, count($counters)));
        $processes = [];
        foreach ($counters as $i => $code) {
            $share = $each + ($i < $count % count($counters) ? 1 : 0);
            $process = new Process([PHP_BINARY, 'artisan', 'shop:rehearse-counter', "--counter={$code}", "--count={$share}",
                '--pace='.(string) $this->option('pace'), "--cashier={$cashier->id}", '--seed='.(string) ($i + 1)], base_path(), null, null, null);
            $process->start();
            $processes[$code] = $process;
        }

        $this->components->info('Selling on '.implode(' and ', $counters)." as {$cashier->name}: {$count} sales.");
        while (array_filter($processes, fn (Process $p) => $p->isRunning()) !== []) {
            foreach ($processes as $process) {
                $out = $process->getIncrementalOutput().$process->getIncrementalErrorOutput();
                if ($out !== '') {
                    $this->output->write($out);
                }
            }
            usleep(200_000);
        }
        $failed = 0;
        foreach ($processes as $code => $process) {
            $this->output->write($process->getIncrementalOutput().$process->getIncrementalErrorOutput());
            if (! $process->isSuccessful()) {
                $failed++;
                $this->components->error("{$code} stopped early.");
            }
        }
        $this->components->info('Done. Now run php artisan shop:rehearsal-check.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

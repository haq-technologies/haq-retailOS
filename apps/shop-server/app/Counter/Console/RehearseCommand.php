<?php

declare(strict_types=1);

namespace App\Counter\Console;

use App\Identity\Models\User;
use App\Identity\Role;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Gate G3's scripted sales (PLAN.md, Phase 3): 300 sales across two counters at once, each counter its own
 * process, through Finish and the print queue to the real printers. The sales are real rows that can never
 * be deleted, so this runs only on the dress-rehearsal install, which is wiped before the shop trades.
 * Pull the power while it runs; then `shop:rehearsal-check` shows what survived.
 */
final class RehearseCommand extends Command
{
    protected $signature = 'shop:rehearse
        {--counters=T01,T02 : The counters that sell}
        {--count=300 : Sales in all, shared between the counters}
        {--pace=2 : Seconds between one counter\'s sales}
        {--cashier= : The cashier who sells (default: the first active cashier)}
        {--confirm : Required: these are real sales on this server}';

    protected $description = 'Gate G3: scripted sales on several counters at once (dress rehearsal only)';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->components->error('These are real sales that can never be deleted. Run it only on the dress-rehearsal install, with --confirm.');

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

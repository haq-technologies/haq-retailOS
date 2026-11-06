<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Database\Mutability\Applier;
use App\Platform\Database\Mutability\DriftCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Applies the mutability matrix (as the owner role, after migrations), or with --check compares the
 * live database with it: the nightly grant check (spec section 4) [F3].
 */
#[AsCommand(name: 'db:mutability')]
class MutabilityCommand extends Command
{
    /** The cache key the health page reads for the last grant check. */
    public const HEALTH_KEY = 'health.grant_check';

    protected $signature = 'db:mutability
        {--check : Compare the live grants and triggers with the matrix instead of applying it}
        {--database= : The database connection to use}';

    protected $description = 'Apply the mutability matrix, or check the live database against it';

    public function handle(Applier $applier, DriftCheck $check): int
    {
        $connection = $this->option('database');
        $db = DB::connection(is_string($connection) ? $connection : null);

        if (! $this->option('check')) {
            $applier->apply($db);
            $this->components->info('The mutability matrix is applied: grants and triggers match it.');

            return self::SUCCESS;
        }

        $problems = $check->problems($db);
        Cache::forever(self::HEALTH_KEY, [
            'checked_at' => now()->toIso8601String(),
            'problems' => $problems,
        ]);

        if ($problems === []) {
            $this->components->info('No drift: the live grants and triggers match the mutability matrix.');

            return self::SUCCESS;
        }

        Log::error('Grant drift: the database no longer matches the mutability matrix.', ['problems' => $problems]);
        $this->components->error('The database no longer matches the mutability matrix:');
        foreach ($problems as $problem) {
            $this->components->bulletList([$problem]);
        }

        return self::FAILURE;
    }
}

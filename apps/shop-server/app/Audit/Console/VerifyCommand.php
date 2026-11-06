<?php

declare(strict_types=1);

namespace App\Audit\Console;

use App\Audit\Verifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'audit:verify')]
class VerifyCommand extends Command
{
    /** The cache key the health page reads for the last chain verification. */
    public const HEALTH_KEY = 'health.audit_verify';

    protected $signature = 'audit:verify';

    protected $description = 'Verify every audit chain and root';

    public function handle(Verifier $verifier): int
    {
        $problems = $verifier->problems();
        Cache::forever(self::HEALTH_KEY, ['checked_at' => now()->toIso8601String(), 'problems' => $problems]);

        if ($problems === []) {
            $this->components->info('Every audit chain and root verifies.');

            return self::SUCCESS;
        }

        Log::error('The audit trail does not verify.', ['problems' => $problems]);
        $this->components->error('The audit trail does not verify:');
        $this->components->bulletList($problems);

        return self::FAILURE;
    }
}

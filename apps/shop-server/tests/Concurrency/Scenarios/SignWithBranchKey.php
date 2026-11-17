<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Organisation\BranchKey;
use Tests\Concurrency\Scenario;

/** Signs with the branch key, as each counter's first sale signs its epoch, while the key may not exist yet. */
final class SignWithBranchKey implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $key = app(BranchKey::class);
        $signature = $key->sign("epoch {$worker}");

        return ['signature' => $signature, 'public' => $key->publicKey()];
    }
}

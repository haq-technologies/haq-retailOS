<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\Enrolment;
use Tests\Concurrency\Scenario;

/** Types a counter's one-time code into a browser at the same moment as other browsers (P6). */
final class RedeemCode implements Scenario
{
    public function run(int $worker, array $args): array
    {
        // A shop keeps its cache in PostgreSQL, so every worker sees the same codes.
        config(['cache.default' => 'database']);

        return ['enrolled' => app(Enrolment::class)->redeem((string) $args['code']) !== null];
    }
}

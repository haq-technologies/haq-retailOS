<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Audit\Roots;
use Tests\Concurrency\Scenario;

/** Makes audit roots while other workers append, as the every-minute job would. */
final class MakeRoots implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $count = is_int($args['count'] ?? null) ? $args['count'] : 5;
        for ($i = 0; $i < $count; $i++) {
            app(Roots::class)->create();
            usleep(20_000);
        }

        return ['roots' => $count];
    }
}

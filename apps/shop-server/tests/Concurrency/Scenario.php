<?php

declare(strict_types=1);

namespace Tests\Concurrency;

/**
 * What one worker process does. It runs as the app role, with real committed transactions, and returns
 * a JSON-encodable result to the test.
 */
interface Scenario
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function run(int $worker, array $args): array;
}

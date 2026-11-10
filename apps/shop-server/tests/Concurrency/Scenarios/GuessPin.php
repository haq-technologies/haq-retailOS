<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\SignIn;
use App\Organisation\Models\Terminal;
use Tests\Concurrency\Scenario;

/** Sends a wrong PIN for one person at the same moment as other workers do (P7). */
final class GuessPin implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = Terminal::query()->findOrFail((string) $args['terminal']);
        $result = app(SignIn::class)->attempt($terminal, (string) $args['user'], (string) $args['pin']);

        return ['outcome' => $result->outcome];
    }
}

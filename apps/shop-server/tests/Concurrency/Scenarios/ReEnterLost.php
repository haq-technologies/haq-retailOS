<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\Models\User;
use App\Sales\BackEntries;
use App\Sales\SaleRefused;
use Tests\Concurrency\Scenario;

/** A manager re-enters a lost sale on the lost-window screen (D44). */
final class ReEnterLost implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $actor = User::query()->findOrFail($args['actor']);
        try {
            return app(BackEntries::class)->reEnter((string) $args['lost'], $actor);
        } catch (SaleRefused $e) {
            return ['refused' => $e->getMessage()];
        }
    }
}

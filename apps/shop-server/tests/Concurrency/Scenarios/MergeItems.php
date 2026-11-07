<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Catalogue\MergeRefused;
use App\Catalogue\Merges;
use App\Identity\Models\User;
use Tests\Concurrency\Scenario;

/** Merges one item into another while someone else merges them the other way round. */
final class MergeItems implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $actor = User::query()->findOrFail($args['actor']);
        try {
            return ['merged' => app(Merges::class)->merge((string) $args['from'], (string) $args['into'], $actor)];
        } catch (MergeRefused $e) {
            return ['refused' => $e->getMessage()];
        }
    }
}

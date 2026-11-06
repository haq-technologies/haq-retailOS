<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Counter\Feed;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Scenario;

/**
 * Worker 0 changes item A and holds its transaction open; worker 1 changes item B and commits at once;
 * worker 2 is a counter polling the feed between the two commits and again after both.
 */
final class FeedLateCommit implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $after = is_int($args['after'] ?? null) ? $args['after'] : 0;
        $a = is_string($args['a'] ?? null) ? $args['a'] : '';
        $b = is_string($args['b'] ?? null) ? $args['b'] : '';

        if ($worker === 0) {
            DB::transaction(function () use ($a): void {
                DB::table('items')->where('id', $a)->update(['display_name' => 'Late change', 'updated_at' => now()]);
                usleep(1_500_000);
            });

            return ['role' => 'late'];
        }
        if ($worker === 1) {
            usleep(300_000);
            DB::transaction(fn () => DB::table('items')->where('id', $b)->update(['display_name' => 'Early change', 'updated_at' => now()]));

            return ['role' => 'early'];
        }

        usleep(800_000);
        $first = app(Feed::class)->changes($after);
        usleep(1_500_000);
        $second = app(Feed::class)->changes($first['seq']);

        $ids = fn (array $page) => array_column($page['rows']['items'] ?? [], 'id');

        return ['role' => 'counter', 'first' => $ids($first), 'second' => $ids($second), 'cursor' => $first['seq']];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use App\Sales\SaleRequest;
use Tests\Concurrency\Scenario;
use Tests\Support\Counter;

/**
 * Pulls the power on a sale: the process is killed outright (SIGKILL), either inside the transaction just
 * after the receipt number is taken, or just after commit, before anything prints.
 */
final class CrashDuringSale implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = Terminal::query()->findOrFail($args['terminal']);
        $cashier = User::query()->findOrFail($args['cashier']);
        /** @var list<string> $items */
        $items = $args['items'];
        $request = SaleRequest::fromArray(Counter::cashSale([$items[0] => [1, 5000]]));

        if (($args['at'] ?? null) === 'before_commit') {
            Finish::$afterNumberTaken = static function (): void {
                posix_kill(getmypid(), SIGKILL);
            };
        }

        app(Finish::class)->finish($terminal, $cashier, $request);

        // After commit, before printing.
        posix_kill(getmypid(), SIGKILL);

        return ['unreachable' => true];
    }
}

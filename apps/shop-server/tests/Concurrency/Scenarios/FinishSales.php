<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\Finish;
use App\Sales\SaleRefused;
use App\Sales\SaleRequest;
use Tests\Concurrency\Scenario;
use Tests\Support\Counter;

/**
 * Finishes sales at one terminal, as a busy counter would. With a fixed sale it sends that exact sale
 * (the same idempotency key). With fail_every, every nth sale has payments that do not add up, so the
 * server refuses it. Each finish is timed.
 */
final class FinishSales implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = Terminal::query()->findOrFail($args['terminal']);
        $cashier = User::query()->findOrFail($args['cashier']);
        $finish = app(Finish::class);
        $count = is_int($args['count'] ?? null) ? $args['count'] : 1;
        $failEvery = is_int($args['fail_every'] ?? null) ? $args['fail_every'] : 0;
        /** @var list<string> $items */
        $items = $args['items'] ?? [];

        $done = [];
        $refused = 0;
        $durations = [];
        for ($i = 1; $i <= $count; $i++) {
            /** @var array<string, mixed> $data */
            // One steady price per item, so the price checks never interrupt the soak; quantities vary.
            $data = is_array($args['sale'] ?? null) ? $args['sale'] : Counter::cashSale([$items[$i % count($items)] => [1 + $i % 3, 1000]]);
            if ($failEvery > 0 && $i % $failEvery === 0) {
                /** @var array{0: array<string, mixed>} $payments */
                $payments = $data['payments'];
                $payments[0]['amount_kobo'] = 1;
                $data['payments'] = $payments;
            }
            $started = microtime(true);
            try {
                $sale = $finish->finish($terminal, $cashier, SaleRequest::fromArray($data));
                $done[] = ['id' => $sale['id'], 'receipt' => $sale['receipt_number'], 'retried' => $sale['retried']];
            } catch (SaleRefused) {
                $refused++;
            }
            $durations[] = (microtime(true) - $started) * 1000;
        }

        return ['done' => $done, 'refused' => $refused, 'durations_ms' => $durations];
    }
}

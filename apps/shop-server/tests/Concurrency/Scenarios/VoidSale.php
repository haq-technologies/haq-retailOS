<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\Reversals;
use App\Sales\SaleRefused;
use Illuminate\Support\Str;
use Tests\Concurrency\Scenario;

/** A manager voids a whole sale at its own counter while another counter returns it (D73, QA finding P14). */
final class VoidSale implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = Terminal::query()->findOrFail($args['terminal']);
        $manager = User::query()->findOrFail($args['manager']);
        try {
            $void = app(Reversals::class)->void($terminal, $manager, (string) $args['sale'], 'Rang twice', (string) Str::uuid(), null);

            return ['voided' => $void['receipt_number']];
        } catch (SaleRefused $e) {
            return ['refused' => $e->getMessage()];
        }
    }
}

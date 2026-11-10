<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Sales\Reversals;
use App\Sales\SaleRefused;
use Illuminate\Support\Str;
use Tests\Concurrency\Scenario;

/** A manager returns a sale's line at one counter while another counter returns the same line (check F3). */
final class ReturnLine implements Scenario
{
    public function run(int $worker, array $args): array
    {
        $terminal = Terminal::query()->findOrFail($args['terminal']);
        $manager = User::query()->findOrFail($args['manager']);
        try {
            $return = app(Reversals::class)->return($terminal, $manager, [
                'request_id' => (string) Str::uuid(), 'receipt_number' => (string) $args['receipt'],
                'lines' => [['line_id' => (string) $args['line'], 'qty' => (int) $args['qty']]],
                'reason' => 'defective', 'disposition' => 'defective_writeoff', 'refund_method' => 'cash', 'refund_reference' => null, 'approval_token' => null,
            ]);

            return ['returned' => $return['receipt_number']];
        } catch (SaleRefused $e) {
            return ['refused' => $e->getMessage()];
        }
    }
}

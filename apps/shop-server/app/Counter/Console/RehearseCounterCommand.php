<?php

declare(strict_types=1);

namespace App\Counter\Console;

use App\Catalogue\Models\Item;
use App\Counter\Feed;
use App\Identity\Models\User;
use App\Organisation\Models\Terminal;
use App\Platform\Ulid;
use App\Sales\Finish;
use App\Sales\Pricing;
use App\Sales\SaleRefused;
use App\Sales\SaleRequest;
use Illuminate\Console\Command;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * One counter's share of the scripted sales (shop:rehearse runs one per counter). Each sale has one to
 * three lines at the item's usual price, and is paid in cash (about 7 in 10), by transfer or by POS.
 */
final class RehearseCounterCommand extends Command
{
    protected $signature = 'shop:rehearse-counter {--counter=} {--count=150} {--pace=2} {--cashier=} {--seed=1}';

    protected $description = 'One counter of the G3 scripted sales';

    protected $hidden = true;

    public function handle(Finish $finish, Pricing $pricing, Feed $feed): int
    {
        $terminal = Terminal::query()->where('code', (string) $this->option('counter'))->first();
        $cashier = User::query()->find((string) $this->option('cashier'));
        if ($terminal === null || ! $terminal->canSell() || $cashier === null) {
            $this->error('No such counter or cashier.');

            return self::FAILURE;
        }
        // Seeded, so a rehearsal can be run again exactly.
        $rng = new Randomizer(new Mt19937((int) $this->option('seed')));

        $items = Item::query()->whereNotIn('status', ['merged', 'archived'])->orderBy('id')->limit(30)->get()->all();
        foreach (array_slice($feed->shapes(), 0, max(0, 12 - count($items))) as $shape) {
            $item = $feed->itemForShape($shape['category_id'], $shape['facets']);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        if ($items === []) {
            $this->error('There are no items to sell. Load the starter catalogue first.');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        $pace = (float) $this->option('pace');
        $refused = 0;
        $last = '';
        for ($n = 1; $n <= $count; $n++) {
            $lines = [];
            $total = 0;
            for ($l = 0, $k = $rng->getInt(1, 3); $l < $k; $l++) {
                $index = $rng->getInt(0, count($items) - 1);
                $item = $items[$index];
                $info = $pricing->info($item, 'retail');
                // The price a cashier would take: the usual one, inside the usual range when there is one.
                $price = $info['prefill'] ?? ($index + 3) * 50_000;
                if ($info['range'] !== null) {
                    $price = max($info['range']['low'], min($info['range']['high'], $price));
                }
                $qty = $rng->getInt(1, 2);
                $total += $qty * $price;
                $lines[] = ['client_line_id' => Ulid::generate(), 'item_id' => $item->id, 'qty' => $qty, 'unit_price_kobo' => $price,
                    'added_by' => 'tile', 'price_source' => $info['prefill'] === null ? 'typed' : 'last_price'];
            }
            $roll = $rng->getInt(1, 10);
            $payment = $roll <= 7
                ? ['method' => 'cash', 'amount_kobo' => $total, 'tendered_kobo' => (int) (ceil($total / 100_000) * 100_000)]
                : ($roll <= 9 ? ['method' => 'transfer', 'amount_kobo' => $total, 'reference' => "G3-{$n}", 'alert_seen' => true] : ['method' => 'pos', 'amount_kobo' => $total]);
            try {
                $sale = $finish->finish($terminal, $cashier, SaleRequest::fromArray([
                    'idempotency_key' => Ulid::generate(), 'customer_type' => 'retail', 'lines' => $lines, 'payments' => [$payment],
                ]));
                $last = is_string($sale['receipt_number'] ?? null) ? $sale['receipt_number'] : $last;
            } catch (SaleRefused $e) {
                $refused++;
                $this->line("{$terminal->code}: sale {$n} refused: {$e->getMessage()}");
            }
            if ($n % 10 === 0 || $n === $count) {
                $this->line("{$terminal->code}: {$n}/{$count} sales, last {$last}".($refused > 0 ? ", {$refused} refused" : ''));
            }
            if ($pace > 0) {
                usleep((int) ($pace * 1_000_000));
            }
        }

        return self::SUCCESS;
    }
}

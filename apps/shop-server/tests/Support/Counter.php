<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Catalogue\Models\Item;
use App\Identity\Models\User;
use App\Identity\Role;
use App\Organisation\Models\Terminal;
use App\Sales\SaleRequest;
use Illuminate\Support\Str;

/**
 * A ready counter for sale tests: a shop with two counters, a cashier, and items to sell.
 */
final class Counter
{
    /**
     * @return array{terminals: list<Terminal>, cashier: User, items: list<Item>}
     */
    public static function ready(int $items = 3): array
    {
        $shop = Shop::create();
        $cashier = People::add('Amina', Role::Cashier, '1357');
        $list = [];
        for ($i = 1; $i <= $items; $i++) {
            $list[] = Catalogue::anyItem("item {$i}");
        }

        return ['terminals' => $shop['terminals'], 'cashier' => $cashier, 'items' => $list];
    }

    /**
     * A cash sale of the given items (item ID => [qty, unit price in kobo]).
     *
     * @param  array<string, array{int, int}>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function cashSale(array $lines, array $overrides = []): array
    {
        $total = 0;
        $body = [];
        foreach ($lines as $itemId => [$qty, $price]) {
            $body[] = ['client_line_id' => (string) Str::uuid(), 'item_id' => $itemId, 'qty' => $qty, 'unit_price_kobo' => $price,
                'added_by' => 'search', 'price_source' => 'typed'];
            $total += $qty * $price;
        }

        return [
            'idempotency_key' => (string) Str::uuid(),
            'customer_type' => 'retail',
            'lines' => $body,
            'payments' => [['method' => 'cash', 'amount_kobo' => $total, 'tendered_kobo' => $total]],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function request(array $data): SaleRequest
    {
        return SaleRequest::fromArray($data);
    }
}

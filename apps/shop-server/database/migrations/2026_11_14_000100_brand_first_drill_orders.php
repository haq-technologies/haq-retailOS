<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The tiles ask the brand first in cables, charger heads, complete chargers and car chargers, as they
 * already do in power banks (spec section 6; D79). The starter catalogue carries the new orders for a new
 * shop; the seeder never changes a template that exists, so this moves a shop already installed.
 *
 * A template is changed only while its drill order is still the one the starter catalogue gave it, so an
 * order the shop changed for itself is left alone. The change reaches every counter through the feed.
 */
return new class extends Migration
{
    /** @var array<string, array{0: list<string>, 1: list<string>}> Category code => [the seeded order, the new order]. */
    private array $orders = [
        'cables' => [['connector_pair', 'length', 'brand', 'rated_power'], ['brand', 'connector_pair', 'length', 'rated_power']],
        'charger_head' => [['wattage', 'brand'], ['brand', 'wattage']],
        'complete_charger' => [['connector_pair', 'wattage', 'brand'], ['brand', 'connector_pair', 'wattage']],
        'car_chargers' => [['wattage', 'brand', 'model'], ['brand', 'wattage', 'model']],
    ];

    public function up(): void
    {
        foreach ($this->orders as $code => [$seeded, $brandFirst]) {
            $this->move($code, $seeded, $brandFirst);
        }
    }

    public function down(): void
    {
        foreach ($this->orders as $code => [$seeded, $brandFirst]) {
            $this->move($code, $brandFirst, $seeded);
        }
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    private function move(string $code, array $from, array $to): void
    {
        DB::update(
            'UPDATE category_templates SET drill_order = ?::jsonb, updated_at = now()
             WHERE drill_order = ?::jsonb AND category_id = (SELECT id FROM categories WHERE code = ?)',
            [json_encode($to, JSON_THROW_ON_ERROR), json_encode($from, JSON_THROW_ON_ERROR), $code],
        );
    }
};

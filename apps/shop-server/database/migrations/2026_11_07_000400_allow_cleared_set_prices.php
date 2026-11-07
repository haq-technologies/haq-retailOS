<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Set prices are append-only, the latest row winning (spec section 5). To take a set price away again (the
 * Undo on the first-sale list, D43), a new row with no price says "no set price from now"; the counter then
 * fills in the last price sold, as before. Existing rows keep their prices; the check still refuses zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE item_prices ALTER COLUMN price_kobo DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM item_prices WHERE price_kobo IS NULL');
        DB::statement('ALTER TABLE item_prices ALTER COLUMN price_kobo SET NOT NULL');
    }
};

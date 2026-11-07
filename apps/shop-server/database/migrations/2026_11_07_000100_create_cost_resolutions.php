<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Backfilled costs (spec section 8) [F14]: when the owner enters a cost for an item whose earlier sale
 * lines had none, each chosen line gets an estimate here. No sale line is ever updated; the newest row
 * for a line is its estimate, and reports show it as Estimated, apart from costs known at sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_resolutions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_line_id')->constrained();
            $table->foreignUlid('item_id')->constrained();
            $table->bigInteger('unit_cost_kobo');
            $table->string('source', 20); // backfill_estimate, owner_batch
            $table->string('basis', 20); // first_null, from_date
            $table->foreignUlid('cost_entry_id')->constrained('cost_entries');
            $table->foreignUlid('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('created_at', 6);
            $table->index(['sale_line_id', 'created_at']);
            $table->index('item_id');
        });
        DB::statement("ALTER TABLE cost_resolutions ADD CONSTRAINT cost_resolutions_source CHECK (source IN ('backfill_estimate', 'owner_batch'))");
        DB::statement('ALTER TABLE cost_resolutions ADD CONSTRAINT cost_resolutions_cost CHECK (unit_cost_kobo >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_resolutions');
    }
};

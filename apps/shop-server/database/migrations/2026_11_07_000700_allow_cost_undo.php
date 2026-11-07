<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Undoing a cost (spec section 2: every cost backfill is reversible) [F14, D48]. An undo changes no row: it
 * adds a cost entry that names the entry it undoes and puts back the cost before it, or none; and for each
 * sale line the undone entry estimated, a newest estimate that puts back the one before, or none. So a cost
 * entry and an estimate may now carry no amount, meaning "no cost from here".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cost_entries ALTER COLUMN unit_cost_kobo DROP NOT NULL');
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->foreignUlid('undoes_id')->nullable()->constrained('cost_entries');
        });
        // An entry is undone at most once.
        DB::statement('CREATE UNIQUE INDEX cost_entries_undone_once ON cost_entries (undoes_id) WHERE undoes_id IS NOT NULL');

        DB::statement('ALTER TABLE cost_resolutions ALTER COLUMN unit_cost_kobo DROP NOT NULL');
        DB::statement('ALTER TABLE cost_resolutions DROP CONSTRAINT cost_resolutions_source');
        DB::statement("ALTER TABLE cost_resolutions ADD CONSTRAINT cost_resolutions_source CHECK (source IN ('backfill_estimate', 'owner_batch', 'undo'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cost_resolutions DROP CONSTRAINT cost_resolutions_source');
        DB::statement("ALTER TABLE cost_resolutions ADD CONSTRAINT cost_resolutions_source CHECK (source IN ('backfill_estimate', 'owner_batch'))");
        DB::statement('DROP INDEX IF EXISTS cost_entries_undone_once');
        Schema::table('cost_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('undoes_id'));
    }
};

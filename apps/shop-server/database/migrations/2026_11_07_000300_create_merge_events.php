<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Item merges and their undo (spec sections 5, 6 and 14 C) [F18]. A merge points one item at the one it
 * now counts under (items.merged_into_id) and changes no sale; reports follow the pointer. Each merge, each
 * undo and each "not the same" answer to a suggested duplicate is a row here, so the history is kept and
 * an undo is a new row, never an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merge_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 10); // merge, undo, not_same
            $table->foreignUlid('item_id')->constrained('items'); // the item merged away, or the first of a pair
            $table->foreignUlid('into_id')->constrained('items'); // the item kept, or the second of a pair
            $table->string('previous_status', 16)->nullable(); // the merged item's status before the merge
            $table->ulid('undoes_id')->nullable(); // the merge an undo reverses
            $table->foreignUlid('actor_id')->constrained('users');
            $table->timestampTz('at', 6);
            $table->index(['item_id', 'at']);
            $table->index(['into_id', 'at']);
        });
        // A key that points back at its own table, added once the table and its primary key exist.
        Schema::table('merge_events', fn (Blueprint $table) => $table->foreign('undoes_id')->references('id')->on('merge_events'));
        DB::statement("ALTER TABLE merge_events ADD CONSTRAINT merge_events_kind CHECK (kind IN ('merge', 'undo', 'not_same'))");
        DB::statement('ALTER TABLE merge_events ADD CONSTRAINT merge_events_two_items CHECK (item_id <> into_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_events');
    }
};

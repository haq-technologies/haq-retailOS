<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The server clock (spec section 4, Security and clock; check E3) [F15, D45]. A date before this version was
 * built, or a jump backward, puts the server in unverified-clock mode: selling goes on, each sale is stamped
 * unverified, and the owner is asked to confirm the time. Each change of state is a row here, never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clock_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Order by this, never by the ID or a time: both come from the clock that may be wrong.
            $table->bigInteger('seq')->unique();
            $table->string('kind', 12); // unverified, confirmed
            $table->string('reason', 16)->nullable(); // before_build, jumped_back
            $table->timestampTz('server_time', 6); // the server's own time, as it was
            $table->timestampTz('last_trusted', 6)->nullable(); // the latest time seen while the clock was trusted
            $table->foreignUlid('actor_id')->nullable()->constrained('users');
        });
        DB::statement("ALTER TABLE clock_events ADD CONSTRAINT clock_events_kind CHECK (kind IN ('unverified', 'confirmed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('clock_events');
    }
};

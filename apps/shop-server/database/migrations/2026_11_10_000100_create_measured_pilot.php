<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The measured pilot (Phase 5; spec section 14, Pilot success thresholds) [F14, D56]. For 14 trading days
 * every counter runs beside the shop's notebook, and the owner decides at gate G5 whether to continue.
 *
 * - pilot_events: the owner starting the 14 days (or starting them again, with a reason) and the owner's
 *   decision, continue or stop, with the scorecard as it stood.
 * - notebook_totals: the notebook's total per payment method for a business day; a correction is a new row,
 *   and the newest counts.
 * - pilot_traces: each difference between the app and the notebook, traced and written down: whose record
 *   was wrong and what happened. A trace written by mistake is withdrawn by a new row that names it.
 *
 * All three are append-only in the mutability matrix. Each orders by its seq, never by an ID or a time,
 * because both come from a clock that may be wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();
            $table->string('kind', 10); // started, decided
            $table->date('first_day')->nullable(); // started: the first day of the 14
            $table->string('decision', 10)->nullable(); // decided: continue, stop
            $table->string('note', 500)->nullable(); // why the 14 days start again, or the owner's words on the decision
            $table->jsonb('scorecard')->nullable(); // decided: the five measures as they stood
            $table->foreignUlid('actor_id')->constrained('users');
            $table->timestampTz('at', 6);
        });
        DB::statement("ALTER TABLE pilot_events ADD CONSTRAINT pilot_events_kind CHECK (kind IN ('started', 'decided'))");
        DB::statement("ALTER TABLE pilot_events ADD CONSTRAINT pilot_events_shape CHECK ((kind = 'started' AND first_day IS NOT NULL AND decision IS NULL) OR (kind = 'decided' AND decision IN ('continue', 'stop') AND scorecard IS NOT NULL))");

        Schema::create('notebook_totals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();
            $table->date('business_day');
            $table->string('method', 10); // cash, transfer, pos
            $table->bigInteger('amount_kobo');
            $table->foreignUlid('entered_by')->constrained('users');
            $table->timestampTz('entered_at', 6);
            $table->index(['business_day', 'method']);
        });
        DB::statement("ALTER TABLE notebook_totals ADD CONSTRAINT notebook_totals_method CHECK (method IN ('cash', 'transfer', 'pos'))");
        DB::statement('ALTER TABLE notebook_totals ADD CONSTRAINT notebook_totals_amount CHECK (amount_kobo >= 0)');

        Schema::create('pilot_traces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();
            $table->date('business_day');
            $table->string('method', 10); // cash, transfer, pos
            // How much of the day's difference (the app's total less the notebook's) this explains.
            $table->bigInteger('amount_kobo');
            $table->string('side', 10); // notebook, app: whose record was wrong
            $table->string('what', 200); // what happened, in the person's words
            $table->string('receipt_number', 20)->nullable(); // the sale it traces to, when one does
            $table->char('withdraws_id', 26)->nullable()->unique(); // the trace this row withdraws
            $table->foreignUlid('entered_by')->constrained('users');
            $table->timestampTz('entered_at', 6);
            $table->index('business_day');
        });
        DB::statement("ALTER TABLE pilot_traces ADD CONSTRAINT pilot_traces_method CHECK (method IN ('cash', 'transfer', 'pos'))");
        DB::statement("ALTER TABLE pilot_traces ADD CONSTRAINT pilot_traces_side CHECK (side IN ('notebook', 'app'))");
        // Added once the table and its key exist: a trace can only withdraw a trace.
        DB::statement('ALTER TABLE pilot_traces ADD CONSTRAINT pilot_traces_withdraws_id_foreign FOREIGN KEY (withdraws_id) REFERENCES pilot_traces (id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_traces');
        Schema::dropIfExists('notebook_totals');
        Schema::dropIfExists('pilot_events');
    }
};

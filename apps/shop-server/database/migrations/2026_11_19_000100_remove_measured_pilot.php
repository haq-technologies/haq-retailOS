<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * The measured pilot is removed entirely (D95): its start and decision, the notebook's totals and the
 * traced differences go with their tables. No shop traded on it (there is no pilot, D67), and its audit
 * entries stay in the audit log, which is never changed. The migration that made the tables stays, so a
 * server already past it and a new one end the same: without them.
 *
 * Rolling back does not bring the pilot back: its tables are no longer in the mutability matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pilot_traces');
        Schema::dropIfExists('notebook_totals');
        Schema::dropIfExists('pilot_events');
    }

    public function down(): void {}
};

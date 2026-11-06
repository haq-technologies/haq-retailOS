<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The sale core (spec sections 4, 5, 7 and 9) [F1, F5, F12, F14, F15, F19, S2, S4, S8, S12, S21]: epochs and
 * receipt counters, shifts per drawer, set prices and cost entries, sales with their lines and payments,
 * print jobs, timings, parked sales and the drawer log. Their mutability is in the matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The branch's own signing key, so epoch records are tamper-evident (spec section 4) [S19].
        Schema::table('branches', fn (Blueprint $table) => $table->string('public_key', 64)->nullable());

        Schema::create('epochs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('terminal_id')->constrained();
            $table->integer('epoch');
            $table->timestampTz('started_at', 6);
            $table->string('reason', 12); // install, restore, replacement, reinstall
            $table->string('signature', 120); // the branch key's signature over the record
            $table->unique(['terminal_id', 'epoch']);
        });

        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('terminal_id')->constrained();
            $table->integer('epoch');
            $table->bigInteger('last_no')->default(0);
            $table->unique(['terminal_id', 'epoch']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained();
            $table->foreignUlid('drawer_id')->constrained();
            $table->foreignUlid('opened_by')->nullable()->constrained('users');
            $table->bigInteger('opening_float_kobo')->nullable();
            $table->date('business_day');
            $table->string('status', 12)->default('open'); // open, closed, auto_closed
            // A shift the counter opened by itself for a sale with no open shift; flagged for the owner [F20].
            $table->boolean('unattributed')->default(false);
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('closed_at', 6)->nullable();
        });
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status CHECK (status IN ('open', 'closed', 'auto_closed'))");
        // One open shift per drawer.
        DB::statement("CREATE UNIQUE INDEX shifts_one_open ON shifts (drawer_id) WHERE status = 'open'");

        Schema::create('shift_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shift_id')->constrained();
            $table->string('type', 20); // open, handover_count, close_count, auto_close
            $table->foreignUlid('cashier_id')->nullable()->constrained('users');
            $table->bigInteger('counted_cash_kobo')->nullable();
            $table->bigInteger('expected_cash_kobo')->nullable();
            $table->bigInteger('variance_kobo')->nullable();
            $table->timestampTz('at', 6);
        });

        Schema::create('item_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained();
            $table->string('customer_type', 10); // retail, wholesale
            $table->bigInteger('price_kobo');
            $table->timestampTz('effective_from', 6);
            $table->foreignUlid('set_by')->nullable()->constrained('users');
            $table->index(['item_id', 'customer_type', 'effective_from']);
        });
        DB::statement("ALTER TABLE item_prices ADD CONSTRAINT item_prices_type CHECK (customer_type IN ('retail', 'wholesale'))");
        DB::statement('ALTER TABLE item_prices ADD CONSTRAINT item_prices_positive CHECK (price_kobo > 0)');

        Schema::create('cost_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained();
            $table->bigInteger('unit_cost_kobo');
            $table->timestampTz('effective_from', 6);
            $table->foreignUlid('entered_by')->nullable()->constrained('users');
            $table->index(['item_id', 'effective_from']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained();
            $table->foreignUlid('terminal_id')->constrained();
            $table->integer('epoch');
            $table->bigInteger('receipt_no');
            $table->string('receipt_number', 20); // T01-E1-000123
            $table->string('idempotency_key', 64);
            $table->char('payload_hash', 64);
            $table->foreignUlid('shift_id')->constrained();
            $table->foreignUlid('cashier_id')->constrained('users');
            $table->string('customer_type', 10);
            $table->string('kind', 12)->default('sale'); // sale, void, back_entry [S15]
            $table->bigInteger('total_kobo');
            $table->date('business_day');
            $table->timestampTz('recorded_at', 6);
            $table->string('clock_state', 12)->default('trusted'); // trusted, unverified, corrected [F15]
            $table->unique(['branch_id', 'terminal_id', 'idempotency_key']);
            $table->unique(['branch_id', 'terminal_id', 'epoch', 'receipt_no']);
            $table->index(['business_day', 'terminal_id']);
        });
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_kind CHECK (kind IN ('sale', 'void', 'back_entry'))");

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained();
            $table->integer('line_no');
            $table->string('client_line_id', 64);
            $table->foreignUlid('item_id')->constrained();
            $table->string('name_snapshot', 160); // the name the receipt printed
            $table->string('colour_note', 40)->nullable(); // never part of the name [D9]
            $table->integer('qty');
            $table->bigInteger('unit_price_kobo');
            $table->bigInteger('line_total_kobo');
            $table->bigInteger('cost_snapshot_kobo')->nullable(); // NULL when no cost was known [F14]
            $table->string('price_source', 12); // set_price, last_price, median, typed
            $table->foreignUlid('approval_id')->nullable()->constrained('approvals');
            $table->jsonb('flags')->default('{}');
            $table->unique(['sale_id', 'line_no']);
            $table->index(['item_id', 'sale_id']);
        });
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_positive CHECK (qty > 0 AND unit_price_kobo > 0 AND line_total_kobo = qty * unit_price_kobo)');

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained();
            $table->string('method', 14); // cash, transfer, pos [D11]
            $table->bigInteger('amount_kobo');
            $table->bigInteger('tendered_kobo')->nullable(); // cash only
            $table->bigInteger('change_kobo')->nullable();
            $table->string('reference', 60)->nullable(); // transfer only
            $table->boolean('alert_seen')->nullable(); // transfer only [F12]
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users');
            $table->timestampTz('confirmed_at', 6)->nullable();
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method CHECK (method IN ('cash', 'transfer', 'pos'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_positive CHECK (amount_kobo > 0)');

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained();
            $table->foreignUlid('terminal_id')->constrained();
            $table->string('kind', 10); // receipt, duplicate
            $table->string('status', 10)->default('pending'); // pending, printed, failed
            $table->integer('attempts')->default(0);
            $table->timestampTz('created_at', 6);
            $table->index(['terminal_id', 'status']);
        });

        Schema::create('sale_timings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->unique()->constrained();
            $table->foreignUlid('terminal_id')->constrained();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('finished_at', 6);
            $table->integer('duration_ms')->nullable();
            $table->jsonb('lines'); // how each line was added: scan, search, tile, new [S8]
        });

        Schema::create('parked_sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained();
            $table->foreignUlid('terminal_id')->constrained();
            $table->string('kind', 18); // hold, awaiting_transfer [S2]
            $table->string('label', 60)->nullable();
            $table->jsonb('payload'); // the cart
            $table->bigInteger('amount_kobo');
            $table->string('status', 10)->default('open'); // open, resumed, discarded, completed
            $table->foreignUlid('parked_by')->constrained('users');
            $table->timestampTz('parked_at', 6);
        });

        Schema::create('parked_sale_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('parked_sale_id')->constrained();
            $table->string('type', 20); // parked, resumed, transfer_confirmed, discarded, completed
            $table->foreignUlid('actor_id')->nullable()->constrained('users');
            $table->foreignUlid('terminal_id')->nullable()->constrained();
            $table->string('reason', 200)->nullable();
            $table->foreignUlid('approval_id')->nullable()->constrained('approvals');
            $table->timestampTz('at', 6);
        });

        // The drawer log: every opening, and cash in or out that is not a sale [F12, S21].
        Schema::create('cash_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shift_id')->nullable()->constrained();
            $table->foreignUlid('drawer_id')->constrained();
            $table->string('type', 20); // sale_open, no_sale_open, key_open, drop, top_up, cash_out_service
            $table->bigInteger('amount_kobo')->nullable();
            $table->string('reason', 200)->nullable();
            $table->foreignUlid('sale_id')->nullable()->constrained();
            $table->foreignUlid('actor_id')->nullable()->constrained('users');
            $table->foreignUlid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('at', 6);
        });
    }

    public function down(): void
    {
        foreach (['cash_events', 'parked_sale_events', 'parked_sales', 'sale_timings', 'print_jobs', 'payments', 'sale_lines', 'sales',
            'cost_entries', 'item_prices', 'shift_events', 'shifts', 'receipt_counters', 'epochs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('public_key'));
    }
};

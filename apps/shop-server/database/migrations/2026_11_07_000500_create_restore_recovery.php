<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Restore recovery (spec sections 4 and 9; check B9 in section 14) [F1, S14].
 *
 * - restores: each restore, server replacement or reinstall, with the epochs it started. The newest one is
 *   also the catalogue feed's generation, so every counter starts its copy afresh after a restore.
 * - journal_reports: each counter reporting its journal (the last seven days of sales it finished) after a
 *   restore, or when its journal is ahead of the server. A counter whose epoch began with a restore sells
 *   again only once it has reported.
 * - lost_sales: sales a counter's journal holds and the restored database lacks; customers may hold
 *   receipts for them.
 * - back_entries: a lost sale re-entered by a manager, under its original receipt number.
 *
 * All four are append-only in the mutability matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reason', 12); // restore, replacement, reinstall
            $table->jsonb('epochs'); // terminal code => the epoch it starts
            $table->timestampTz('restored_to', 6)->nullable(); // the newest sale in the restored data
            $table->timestampTz('at', 6);
        });
        DB::statement("ALTER TABLE restores ADD CONSTRAINT restores_reason CHECK (reason IN ('restore', 'replacement', 'reinstall'))");

        Schema::create('journal_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('terminal_id')->constrained();
            $table->foreignUlid('restore_id')->nullable()->constrained('restores');
            $table->integer('epoch'); // the counter's epoch once the report was taken in
            $table->jsonb('highest'); // epoch => the highest receipt number the journal holds
            $table->integer('missing'); // journal sales the database lacked
            $table->integer('raised_to')->nullable(); // a new epoch the journal made necessary
            $table->timestampTz('reported_at', 6);
            $table->index(['terminal_id', 'epoch']);
        });

        Schema::create('lost_sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('terminal_id')->constrained();
            $table->foreignUlid('journal_report_id')->constrained('journal_reports');
            $table->string('receipt_number', 20);
            $table->integer('epoch');
            $table->bigInteger('receipt_no');
            $table->timestampTz('sold_at', 6);
            $table->date('business_day');
            // The journal names the cashier; they may be missing from the restored data, so no key.
            $table->char('cashier_id', 26)->nullable();
            $table->string('cashier_name', 80)->nullable();
            $table->string('customer_type', 10);
            $table->bigInteger('total_kobo');
            $table->jsonb('entry'); // the lines and payments, as the counter's journal holds them
            $table->unique(['terminal_id', 'epoch', 'receipt_no']);
        });

        Schema::create('back_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->unique()->constrained();
            $table->foreignUlid('lost_sale_id')->nullable()->unique()->constrained('lost_sales');
            $table->string('source', 12); // lost_window; paper_pad comes with the MVP's back-entry screen
            $table->string('reference', 40); // the receipt number the customer holds
            $table->timestampTz('sold_at', 6);
            $table->foreignUlid('entered_by')->constrained('users');
            $table->timestampTz('entered_at', 6);
        });
        DB::statement("ALTER TABLE back_entries ADD CONSTRAINT back_entries_source CHECK (source IN ('lost_window', 'paper_pad'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('back_entries');
        Schema::dropIfExists('lost_sales');
        Schema::dropIfExists('journal_reports');
        Schema::dropIfExists('restores');
    }
};

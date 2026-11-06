<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Audit module (spec section 10) [F4, F10]. Each terminal has its own hash chain and each branch one
 * more; roots hash every chain head. All three tables are append-only in the mutability matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // "terminal:<ULID>" or "branch:<ULID>"
            $table->string('chain_id', 40);
            $table->bigInteger('seq');
            $table->foreignUlid('branch_id')->constrained();
            $table->foreignUlid('terminal_id')->nullable()->constrained();
            $table->ulid('actor_id')->nullable();
            $table->string('device', 40)->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 60)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->jsonb('redacted_before')->nullable();
            $table->jsonb('redacted_after')->nullable();
            $table->char('sensitive_hash', 64)->nullable();
            // True when the entry waited in the spool because the chain was busy.
            $table->boolean('spooled')->default(false);
            $table->timestampTz('recorded_at', 6);
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->unique(['chain_id', 'seq']);
            $table->index(['actor_id', 'recorded_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Cost, credit limits and similar values, readable only by the owner (spec section 10) [F10].
        Schema::create('audit_sensitive', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('audit_id')->unique()->constrained('audit_log');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
        });

        Schema::create('audit_roots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();
            // Every chain head when the root was made: {"<chain_id>": {"seq": n, "hash": "..."}}
            $table->jsonb('heads');
            $table->string('reason', 20);
            $table->timestampTz('recorded_at', 6);
            $table->char('prev_root', 64);
            $table->char('hash', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_roots');
        Schema::dropIfExists('audit_sensitive');
        Schema::dropIfExists('audit_log');
    }
};

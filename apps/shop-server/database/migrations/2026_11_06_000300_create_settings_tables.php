<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Settings module (spec sections 5 and 11) and the drawers each counter may have (S21).
 * A setting lives at shop or branch scope; each change adds a settings_history row and an audit entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 10); // platform, shop or branch
            $table->ulid('scope_id')->nullable(); // the branch, for branch scope
            $table->string('key', 80);
            $table->jsonb('value');
            $table->boolean('locked_by_sysadmin')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_scope CHECK (scope IN ('platform', 'shop', 'branch'))");
        DB::statement('CREATE UNIQUE INDEX settings_one_value ON settings (scope, scope_id, key) NULLS NOT DISTINCT');

        Schema::create('settings_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 10);
            $table->ulid('scope_id')->nullable();
            $table->string('key', 80);
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');
            $table->boolean('locked_by_sysadmin');
            $table->ulid('changed_by')->nullable();
            $table->string('source', 20); // profile, console, owner, system
            $table->timestampTz('changed_at', 6);
            $table->index(['key', 'changed_at']);
        });

        // A counter's cash drawer, if fitted (S21). A counter with none still has a row, for cash accountability [F19].
        Schema::create('drawers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained();
            $table->foreignUlid('terminal_id')->unique()->constrained();
            $table->string('label', 60);
            $table->boolean('fitted');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawers');
        Schema::dropIfExists('settings_history');
        Schema::dropIfExists('settings');
    }
};

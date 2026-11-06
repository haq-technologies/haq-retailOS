<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Identity module (spec section 3) [F8, F9, S3, S6]: people with name tiles and PINs, sign-in
 * attempts (for lockouts and backoff), bound manager approvals, and the device secret that enrols a
 * counter PC as a terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 60);
            $table->string('role', 12);
            $table->string('pin_hash')->nullable(); // Argon2id
            $table->timestampTz('pin_changed_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->ulid('deactivated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role CHECK (role IN ('cashier', 'manager', 'storekeeper', 'owner'))");

        // Every audit row's actor is a real person; users are never deleted, so this always holds.
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreign('actor_id')->references('id')->on('users');
        });

        Schema::table('terminals', function (Blueprint $table) {
            $table->char('device_secret_hash', 64)->nullable();
            $table->timestampTz('enrolled_at')->nullable();
        });

        Schema::create('sign_in_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained();
            $table->foreignUlid('terminal_id')->constrained();
            $table->string('kind', 10); // sign_in or approval
            $table->boolean('succeeded');
            $table->timestampTz('attempted_at', 6);
            $table->index(['user_id', 'attempted_at']);
            $table->index(['terminal_id', 'attempted_at']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('action', 40);
            $table->string('subject_id', 64)->nullable(); // the client's line ID, for a price
            $table->ulid('item_id')->nullable(); // gets its foreign key when the catalogue's items table exists
            $table->bigInteger('price_kobo')->nullable();
            $table->foreignUlid('approver_id')->constrained('users');
            $table->foreignUlid('requested_by')->nullable()->constrained('users');
            $table->foreignUlid('terminal_id')->constrained();
            $table->char('token_hash', 64)->unique();
            // An approval by a manager not signed in at this branch today is flagged for the owner [F8].
            $table->boolean('flagged');
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('bound_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('sign_in_attempts');
        Schema::table('terminals', fn (Blueprint $table) => $table->dropColumn(['device_secret_hash', 'enrolled_at']));
        Schema::table('audit_log', fn (Blueprint $table) => $table->dropForeign(['actor_id']));
        Schema::dropIfExists('users');
    }
};

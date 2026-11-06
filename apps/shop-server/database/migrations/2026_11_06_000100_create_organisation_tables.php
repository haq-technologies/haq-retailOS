<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Organisation module: the shop (a tenant), its branches and each branch's terminals (spec sections 4
 * and 5). A shop server runs one branch, marked is_local; other branches arrive with sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->timestampsTz();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained();
            $table->string('code', 8)->unique();
            $table->string('name', 120);
            $table->boolean('is_local')->default(false);
            $table->timestampsTz();
        });
        // One local branch per server.
        DB::statement('CREATE UNIQUE INDEX branches_one_local ON branches (is_local) WHERE is_local');

        Schema::create('terminals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained();
            // The code that starts every receipt number: T01-E1-000123 (spec section 4).
            $table->string('code', 3);
            $table->string('name', 60);
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['branch_id', 'code']);
        });
        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_code_format CHECK (code ~ '^T[0-9]{2}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('tenants');
    }
};

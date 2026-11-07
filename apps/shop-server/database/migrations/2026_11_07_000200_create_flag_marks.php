<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Flags marked as seen (spec sections 8 and 12). A flag is read from the records that caused it, so it can
 * never be deleted; marking it as seen adds a row here, and the flag stays on the list with who saw it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flag_marks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('flag_key', 80); // "<kind>:<ULID of the record that raised it>"
            $table->foreignUlid('seen_by')->constrained('users');
            $table->timestampTz('seen_at', 6);
            $table->index('flag_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flag_marks');
    }
};

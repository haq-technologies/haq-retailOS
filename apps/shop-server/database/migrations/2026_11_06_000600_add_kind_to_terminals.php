<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A terminal is a counter (T01), which sells, or a back-office device (D01), such as the owner's phone or
 * laptop on the shop's network, which signs in the same way but never sells (D29).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', fn (Blueprint $table) => $table->string('kind', 12)->default('counter'));
        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_kind CHECK (kind IN ('counter', 'back_office'))");
        DB::statement('ALTER TABLE terminals DROP CONSTRAINT terminals_code_format');
        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_code_format CHECK ((kind = 'counter' AND code ~ '^T[0-9]{2}$') OR (kind = 'back_office' AND code ~ '^D[0-9]{2}$'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE terminals DROP CONSTRAINT terminals_code_format');
        DB::statement("ALTER TABLE terminals ADD CONSTRAINT terminals_code_format CHECK (code ~ '^T[0-9]{2}$')");
        DB::statement('ALTER TABLE terminals DROP CONSTRAINT terminals_kind');
        Schema::table('terminals', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};

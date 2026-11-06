<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Each paying counter has an 80 mm network printer with a fixed IP (spec section 4) [R3]. The server
 * prints to it on port 9100; the counter PC needs nothing installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->string('printer_host', 64)->nullable();
            $table->integer('printer_port')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terminals', fn (Blueprint $table) => $table->dropColumn(['printer_host', 'printer_port']));
    }
};

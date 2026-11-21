<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A transfer's sender, kept apart from the sale (D112, finding DP6): the name the bank alert shows and a
 * phone number the customer gives, both optional, so the shop can reach the sender if the bank reverses
 * the transfer. The sale and its payment are append-only and kept for years; these two fields are not
 * part of the sale, so they live here and the nightly clear-up deletes them after the days set in
 * payments.transfer_contact_days (90). They never print, and the owner's export leaves them out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->unique()->constrained();
            $table->foreignUlid('sale_id')->constrained();
            $table->string('sender_name', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestampTz('recorded_at', 6)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_contacts');
    }
};

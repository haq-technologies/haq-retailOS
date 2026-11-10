<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A manager's price approval is bound to its line when it is granted and holds while the cart is parked
 * (spec section 3) [S3]. It still works once: at most one sale line ever carries a given approval,
 * which this index enforces even if two Finish requests race with the same token.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX sale_lines_one_per_approval ON sale_lines (approval_id) WHERE approval_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sale_lines_one_per_approval');
    }
};

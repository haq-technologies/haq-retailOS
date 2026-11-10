<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The go-live basics (spec section 9; D68): voids and returns as reversing sales, and the drawer's paid-outs,
 * drops and top-ups beside the day-end close.
 *
 * - A void or a return is a sale of its own kind, with its own receipt number, whose lines and payments are
 *   the reverse of what they undo: negative quantities and amounts. So every total that sums sales nets them
 *   by itself, and the sale they undo is never edited. Each reversing line names the line it reverses.
 * - A sign check holds that pairing in the database: a sale or a back entry has only positive lines and
 *   payments, a void or a return only negative ones.
 * - returns records each returned line: the line it came from, the reason and the disposition [F7].
 * - A sale is voided at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_kind');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_kind CHECK (kind IN ('sale', 'void', 'return', 'back_entry'))");
        Schema::table('sales', fn (Blueprint $table) => $table->foreignUlid('reverses_sale_id')->nullable()->constrained('sales'));
        DB::statement("CREATE UNIQUE INDEX sales_one_void ON sales (reverses_sale_id) WHERE kind = 'void'");
        // A void always names the sale it undoes; a return names it when there was a receipt.
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_reverses CHECK (kind <> 'void' OR reverses_sale_id IS NOT NULL)");

        Schema::table('sale_lines', fn (Blueprint $table) => $table->foreignUlid('reverses_line_id')->nullable()->constrained('sale_lines'));
        DB::statement('ALTER TABLE sale_lines DROP CONSTRAINT sale_lines_positive');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_amounts CHECK (qty <> 0 AND unit_price_kobo > 0 AND line_total_kobo = qty * unit_price_kobo)');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_positive');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount CHECK (amount_kobo <> 0)');

        // A line's and a payment's sign follow their sale's kind: only a void or a return reverses money.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION go_live_sign_follows_kind() RETURNS trigger LANGUAGE plpgsql AS $fn$
            DECLARE
                sale_kind text;
                amount bigint;
            BEGIN
                SELECT kind INTO sale_kind FROM sales WHERE id = NEW.sale_id;
                -- Read through JSON: NEW is a sale line or a payment, and only one of the two has each field.
                amount := (to_jsonb(NEW) ->> CASE TG_TABLE_NAME WHEN 'sale_lines' THEN 'qty' ELSE 'amount_kobo' END)::bigint;
                IF (sale_kind IN ('void', 'return')) <> (amount < 0) THEN
                    RAISE EXCEPTION 'HAQ Retail OS: a % % must be % here', sale_kind, TG_TABLE_NAME,
                        CASE WHEN sale_kind IN ('void', 'return') THEN 'negative' ELSE 'positive' END USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $fn$
            SQL);
        DB::statement('CREATE TRIGGER go_live_line_sign BEFORE INSERT ON sale_lines FOR EACH ROW EXECUTE FUNCTION go_live_sign_follows_kind()');
        DB::statement('CREATE TRIGGER go_live_payment_sign BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION go_live_sign_follows_kind()');

        Schema::create('returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained(); // the return sale
            $table->foreignUlid('sale_line_id')->unique()->constrained(); // its line
            $table->foreignUlid('original_line_id')->nullable()->constrained('sale_lines'); // none: a return with no receipt
            $table->integer('qty');
            $table->string('reason_code', 20);
            $table->string('disposition', 20);
            $table->foreignUlid('approved_by')->nullable()->constrained('users');
            $table->foreignUlid('approval_id')->nullable()->constrained('approvals');
            $table->timestampTz('at', 6);
            $table->index('original_line_id');
        });
        DB::statement('ALTER TABLE returns ADD CONSTRAINT returns_qty CHECK (qty > 0)');
        DB::statement("ALTER TABLE returns ADD CONSTRAINT returns_reason CHECK (reason_code IN ('wrong_item', 'defective', 'changed_mind'))");
        DB::statement("ALTER TABLE returns ADD CONSTRAINT returns_disposition CHECK (disposition IN ('restock', 'defective_writeoff', 'supplier_claim'))");

        // The drawer log gains paid-outs; drops and top-ups were reserved for it from the start [F19].
        DB::statement("ALTER TABLE cash_events ADD CONSTRAINT cash_events_type CHECK (type IN ('sale_open', 'no_sale_open', 'key_open', 'drop', 'top_up', 'paid_out', 'cash_out_service'))");
        DB::statement("ALTER TABLE cash_events ADD CONSTRAINT cash_events_amount CHECK (type NOT IN ('drop', 'top_up', 'paid_out') OR amount_kobo > 0)");
        Schema::table('cash_events', function (Blueprint $table) {
            $table->foreignUlid('approval_id')->nullable()->constrained('approvals');
            // The counter's own key for a paid-out, a drop or a top-up, so a retry after a lost answer records it once.
            $table->string('request_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('cash_events', function (Blueprint $table) {
            $table->dropUnique(['request_id']);
            $table->dropColumn('request_id');
            $table->dropConstrainedForeignId('approval_id');
        });
        DB::statement('ALTER TABLE cash_events DROP CONSTRAINT IF EXISTS cash_events_amount');
        DB::statement('ALTER TABLE cash_events DROP CONSTRAINT IF EXISTS cash_events_type');
        Schema::dropIfExists('returns');
        DB::statement('DROP TRIGGER IF EXISTS go_live_payment_sign ON payments');
        DB::statement('DROP TRIGGER IF EXISTS go_live_line_sign ON sale_lines');
        DB::statement('DROP FUNCTION IF EXISTS go_live_sign_follows_kind()');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_amount');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_positive CHECK (amount_kobo > 0)');
        DB::statement('ALTER TABLE sale_lines DROP CONSTRAINT sale_lines_amounts');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_positive CHECK (qty > 0 AND unit_price_kobo > 0 AND line_total_kobo = qty * unit_price_kobo)');
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('reverses_line_id'));
        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_reverses');
        DB::statement('DROP INDEX IF EXISTS sales_one_void');
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('reverses_sale_id'));
        DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_kind');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_kind CHECK (kind IN ('sale', 'void', 'back_entry'))");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A sale is never both voided and returned (spec section 9; D73, QA finding P14). The app already refuses a
 * void of a sale with a return, and a return from a voided sale; this rule holds it in the database too,
 * even when the two arrive at the same moment from two counters.
 *
 * Before a void or a return that names its sale is written, the rule takes the same lock the app takes for
 * that sale ('reverse-sale:' and the sale's ID), so the second of the two waits for the first to commit, and
 * then looks for the other kind. A return with no receipt names no sale and is not affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION go_live_void_or_return() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('reverse-sale:' || NEW.reverses_sale_id, 0));
                IF EXISTS (
                    SELECT 1 FROM sales
                    WHERE reverses_sale_id = NEW.reverses_sale_id
                      AND kind = CASE NEW.kind WHEN 'void' THEN 'return' ELSE 'void' END
                ) THEN
                    RAISE EXCEPTION 'HAQ Retail OS: sale % is already %, so it cannot be % as well', NEW.reverses_sale_id,
                        CASE NEW.kind WHEN 'void' THEN 'partly returned' ELSE 'voided' END,
                        CASE NEW.kind WHEN 'void' THEN 'voided' ELSE 'returned' END USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $fn$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER go_live_void_or_return BEFORE INSERT ON sales FOR EACH ROW
            WHEN (NEW.kind IN ('void', 'return') AND NEW.reverses_sale_id IS NOT NULL)
            EXECUTE FUNCTION go_live_void_or_return()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS go_live_void_or_return ON sales');
        DB::statement('DROP FUNCTION IF EXISTS go_live_void_or_return()');
    }
};

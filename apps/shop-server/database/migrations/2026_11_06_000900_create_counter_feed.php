<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The counter's catalogue feed (spec section 4) [R5, F25] and what the counter learns (spec section 6) [F13].
 *
 * Every committed change to the catalogue, and to what moves an item's price pre-fill and usual range (set
 * prices and sale lines), adds a row to catalogue_changes with a monotonic seq. Each counter polls for the
 * changes after the last seq it saw. The row is written by a deferred trigger, at commit, under a short
 * lock, so seq order is commit order: a counter that has seen seq N has seen every change below N, and a
 * change that commits late can never be skipped. The trigger runs as the owner, so the app role writes
 * nothing to the log itself.
 */
return new class extends Migration
{
    /** @var array<string, string> table => the column the change names: the row, or for prices the item */
    private array $watched = [
        'categories' => 'id', 'category_templates' => 'id', 'picklist_values' => 'id', 'brands' => 'id',
        'product_models' => 'id', 'items' => 'id', 'item_facets' => 'id', 'barcodes' => 'id', 'aliases' => 'id',
        'item_prices' => 'item_id', 'sale_lines' => 'item_id',
    ];

    public function up(): void
    {
        // Trigram similarity, for the similar-item warning and the server's search fallback [S10].
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('barcodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 120); // a scanner types QR text too
            $table->foreignUlid('item_id')->constrained();
            $table->timestampTz('learned_at', 6);
            $table->foreignUlid('learned_by')->nullable()->constrained('users');
            $table->string('status', 12)->default('unconfirmed'); // unconfirmed, confirmed, disabled
            $table->integer('confirmations')->default(0);
            $table->boolean('conflicted')->default(false);
            $table->timestampTz('last_chosen_at', 6)->nullable();
            $table->unique(['code', 'item_id']);
            $table->index('code');
        });
        DB::statement("ALTER TABLE barcodes ADD CONSTRAINT barcodes_status CHECK (status IN ('unconfirmed', 'confirmed', 'disabled'))");

        Schema::create('barcode_ignore', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 120)->unique();
            $table->string('reason', 120);
        });

        Schema::create('aliases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained();
            $table->string('term', 60);
            $table->integer('hits')->default(0);
            $table->string('status', 10)->default('candidate'); // candidate, active
            $table->unique(['item_id', 'term']);
        });

        Schema::create('catalogue_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->unique();
            $table->string('table_name', 40);
            $table->char('row_id', 26);
            $table->timestampTz('at', 6);
        });

        // Owned by the log's column, so dropping the table (as migrate:fresh does) drops the sequence too.
        DB::statement('CREATE SEQUENCE catalogue_seq OWNED BY catalogue_changes.seq');
        // A lowercase ULID made in the database, as App\Platform\Ulid makes them in PHP.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION feed_ulid() RETURNS char(26) LANGUAGE plpgsql VOLATILE AS $fn$
            DECLARE
                alphabet constant text := '0123456789abcdefghjkmnpqrstvwxyz';
                ms bigint := floor(extract(epoch FROM clock_timestamp()) * 1000);
                u bytea := decode(replace(gen_random_uuid()::text, '-', ''), 'hex');
                rnd bytea := substring(u FROM 1 FOR 6) || substring(u FROM 10 FOR 4);
                result text := '';
                v bigint;
            BEGIN
                FOR i IN REVERSE 9..0 LOOP
                    result := result || substr(alphabet, ((ms >> (i * 5)) & 31)::int + 1, 1);
                END LOOP;
                FOR h IN 0..1 LOOP
                    v := 0;
                    FOR i IN 0..4 LOOP
                        v := (v << 8) | get_byte(rnd, h * 5 + i);
                    END LOOP;
                    FOR i IN REVERSE 7..0 LOOP
                        result := result || substr(alphabet, ((v >> (i * 5)) & 31)::int + 1, 1);
                    END LOOP;
                END LOOP;
                RETURN result;
            END
            $fn$
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION feed_log_change() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $fn$
            DECLARE
                row_ref jsonb := to_jsonb(CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END);
            BEGIN
                -- Held from here to the end of the commit, so a later seq can never commit first.
                PERFORM pg_advisory_xact_lock(hashtextextended('catalogue_feed', 0));
                INSERT INTO catalogue_changes (id, seq, table_name, row_id, at)
                VALUES (feed_ulid(), nextval('catalogue_seq'), TG_TABLE_NAME, row_ref ->> TG_ARGV[0], clock_timestamp());
                RETURN NULL;
            END
            $fn$
            SQL);

        foreach ($this->watched as $table => $column) {
            $events = in_array($table, ['item_prices', 'sale_lines'], true) ? 'INSERT' : 'INSERT OR UPDATE OR DELETE';
            DB::statement("CREATE CONSTRAINT TRIGGER feed_change AFTER {$events} ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION feed_log_change('{$column}')");
        }

        // What exists already is the first set of changes, so a counter's first snapshot has a seq to start from.
        foreach ($this->watched as $table => $column) {
            if ($table === 'sale_lines') {
                DB::statement("INSERT INTO catalogue_changes (id, seq, table_name, row_id, at) SELECT feed_ulid(), nextval('catalogue_seq'), 'sale_lines', item_id, now() FROM (SELECT DISTINCT item_id FROM sale_lines) s");

                continue;
            }
            DB::statement("INSERT INTO catalogue_changes (id, seq, table_name, row_id, at) SELECT feed_ulid(), nextval('catalogue_seq'), '{$table}', {$column}, now() FROM {$table}");
        }

        DB::statement('CREATE INDEX items_search_trgm ON items USING gin (search_text gin_trgm_ops)');
    }

    public function down(): void
    {
        foreach (array_keys($this->watched) as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("DROP TRIGGER IF EXISTS feed_change ON {$table}");
            }
        }
        DB::statement('DROP INDEX IF EXISTS items_search_trgm');
        Schema::dropIfExists('catalogue_changes');
        Schema::dropIfExists('aliases');
        Schema::dropIfExists('barcode_ignore');
        Schema::dropIfExists('barcodes');
        DB::statement('DROP FUNCTION IF EXISTS feed_log_change()');
        DB::statement('DROP FUNCTION IF EXISTS feed_ulid()');
        DB::statement('DROP SEQUENCE IF EXISTS catalogue_seq');
    }
};

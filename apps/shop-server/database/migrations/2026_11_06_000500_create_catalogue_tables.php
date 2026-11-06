<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Catalogue module (spec sections 5 and 6) [R1, R2, C2, C9]: a small category tree with templates,
 * pick-lists, brands, product models and flat items whose identity key makes a duplicate impossible.
 * Seed entries keep their permanent IDs in seed_id, so a later pull can tell a rename from a new entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique(); // permanent
            $table->ulid('parent_id')->nullable();
            $table->string('name', 60);
            $table->string('word', 40)->nullable(); // what the receipt prints
            $table->boolean('sellable');
            $table->jsonb('aliases')->default('[]');
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        // Every sellable category has a word, so a name is never blank [C1].
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_sellable_word CHECK (NOT sellable OR word IS NOT NULL)');

        Schema::create('category_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->unique()->constrained();
            $table->jsonb('facets'); // [{key, label, type, list?}]
            $table->jsonb('drill_order');
            $table->jsonb('name_order');
            $table->jsonb('generator')->nullable(); // {combine, blank_allowed, exclude}
            $table->boolean('asks_colour')->default(false); // ticked by the owner at install [D9]
            $table->timestampsTz();
        });

        Schema::create('picklist_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seed_id', 80)->nullable()->unique();
            $table->string('list', 40);
            $table->string('name', 80);
            $table->jsonb('aliases')->default('[]');
            $table->ulid('parent_id')->nullable(); // a phone model's family
            $table->string('status', 10)->default('active'); // active, pending, archived
            $table->string('source', 10)->default('starter');
            $table->integer('position')->default(0);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX picklist_values_one_name ON picklist_values (list, lower(name))');

        Schema::create('brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seed_id', 80)->nullable()->unique();
            $table->string('name', 60);
            $table->jsonb('aliases')->default('[]');
            $table->jsonb('shown_first_in')->default('[]'); // category codes
            $table->string('status', 10)->default('approved'); // approved, pending, merged
            $table->ulid('merged_into_id')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users');
            $table->string('source', 10)->default('starter');
            $table->timestampsTz();
        });
        // Brand names are unique ignoring case, so "Poga" and "poga" cannot both exist (spec section 6).
        DB::statement('CREATE UNIQUE INDEX brands_one_name ON brands (lower(name))');

        // The seed's models and product lines (D25): suggestions for an item's model, by brand and category.
        Schema::create('product_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('seed_id', 80)->nullable()->unique();
            $table->foreignUlid('brand_id')->nullable()->constrained();
            $table->string('name', 60);
            $table->jsonb('categories')->default('[]'); // category codes
            $table->string('status', 10)->default('active');
            $table->string('source', 10)->default('starter');
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX product_models_one_name ON product_models (coalesce(brand_id, ''), lower(name))");

        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained();
            $table->foreignUlid('brand_id')->nullable()->constrained();
            $table->string('brand_text', 60)->nullable(); // a typed brand not yet approved
            $table->string('free_text', 80)->nullable();
            $table->string('typed_category_word', 40)->nullable(); // from "Not in the list"
            $table->char('item_code', 6)->unique(); // auto, immutable
            $table->string('display_name', 160); // built by the name routine, cached
            $table->text('search_text');
            // The category, brand, normalised facet values and normalised free text, joined: a second identical item cannot exist [R1].
            $table->text('identity_key')->unique();
            $table->string('status', 16)->default('active'); // active, pending_review, pending_category, archived, merged
            $table->ulid('merged_into_id')->nullable();
            $table->string('source', 10); // starter, owner, cashier, import, generated
            $table->foreignUlid('created_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('item_facets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained();
            $table->string('facet_key', 40);
            $table->foreignUlid('picklist_value_id')->nullable()->constrained('picklist_values');
            $table->string('text_value', 80)->nullable();
            $table->unique(['item_id', 'facet_key']);
        });
        DB::statement('ALTER TABLE item_facets ADD CONSTRAINT item_facets_one_value CHECK ((picklist_value_id IS NULL) <> (text_value IS NULL))');

        // Keys that point back at their own table, added once the table and its primary key exist.
        Schema::table('categories', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('categories'));
        Schema::table('picklist_values', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('picklist_values'));
        Schema::table('brands', fn (Blueprint $table) => $table->foreign('merged_into_id')->references('id')->on('brands'));
        Schema::table('items', fn (Blueprint $table) => $table->foreign('merged_into_id')->references('id')->on('items'));

        // Approvals named an item before items existed; now the key can be enforced.
        Schema::table('approvals', fn (Blueprint $table) => $table->foreign('item_id')->references('id')->on('items'));
    }

    public function down(): void
    {
        Schema::table('approvals', fn (Blueprint $table) => $table->dropForeign(['item_id']));
        foreach (['item_facets', 'items', 'product_models', 'brands', 'picklist_values', 'category_templates', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

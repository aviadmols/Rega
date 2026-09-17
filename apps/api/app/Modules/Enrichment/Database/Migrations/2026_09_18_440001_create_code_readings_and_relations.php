<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What code read from each product before any model: brand, size family, a type the
        // category stands for, sizes in the title, choices to make, price unit. Kept whole, so the
        // log shows exactly what code saw.
        Schema::create('enrichment_code_readings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('vocabulary_id')->nullable()->constrained('enrichment_vocabularies')->nullOnDelete();
            $table->string('family_key', 32)->nullable();
            $table->string('brand', 120)->nullable();
            $table->json('reading');
            $table->string('reading_hash', 64);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique('product_id');
            $table->index(['shop_id', 'family_key']);
            $table->index(['shop_id', 'brand']);
        });

        // Rules for what goes with what across a shop's vocabularies, versioned like vocabularies.
        Schema::create('enrichment_relation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->string('definition_hash', 64);
            $table->string('author', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'version']);
        });

        // Products shown with a product: complements, other sizes, alternatives. Computed in code,
        // each with the reasons it was chosen.
        Schema::create('enrichment_product_relations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('related_product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('source', 60);
            $table->integer('score');
            $table->json('reasons');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id', 'kind']);
            $table->index(['shop_id', 'product_id', 'kind', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_product_relations');
        Schema::dropIfExists('enrichment_relation_rules');
        Schema::dropIfExists('enrichment_code_readings');
    }
};

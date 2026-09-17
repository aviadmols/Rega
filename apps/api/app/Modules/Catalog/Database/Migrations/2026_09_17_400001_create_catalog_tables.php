<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's catalog as its store feed describes it. Rows are never deleted by a sync: a record
 * the store stopped publishing gets removed_at, so facts and history that point to it survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('parent_external_id', 64)->nullable();
            $table->string('name');
            $table->json('path');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('product_count')->default(0);
            $table->text('url')->nullable();
            $table->text('image_url')->nullable();
            $table->string('hash', 64);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'external_id']);
        });

        Schema::create('catalog_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('title', 500);
            $table->text('url')->nullable();
            $table->string('sku', 120)->nullable();
            $table->string('brand', 120)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('on_sale')->default(false);
            $table->boolean('in_stock')->default(false);
            $table->boolean('purchasable')->default(false);
            $table->text('image_url')->nullable();
            $table->unsignedInteger('variations_count')->default(0);
            $table->timestamp('source_updated_at')->nullable();
            $table->string('hash', 64);
            // The feed record as received, minus fields that must never be stored (costs, notes).
            $table->json('payload');
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'external_id']);
            $table->index(['shop_id', 'removed_at', 'in_stock']);
        });

        Schema::create('catalog_category_product', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('category_id')->constrained('catalog_categories')->cascadeOnDelete();

            $table->primary(['product_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('catalog_content', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('external_id', 64);
            $table->string('title', 500);
            $table->text('url')->nullable();
            $table->text('image_url')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->json('terms')->nullable();
            $table->json('product_external_ids')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('hash', 64);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_content');
        Schema::dropIfExists('catalog_category_product');
        Schema::dropIfExists('catalog_products');
        Schema::dropIfExists('catalog_categories');
    }
};

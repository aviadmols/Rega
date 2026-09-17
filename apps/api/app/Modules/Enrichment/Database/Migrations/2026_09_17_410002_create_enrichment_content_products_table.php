<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Products to show next to an article, computed in code from checked facts.
        Schema::create('enrichment_content_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('content_id')->constrained('catalog_content')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedInteger('score');
            $table->json('reasons');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['content_id', 'product_id']);
            $table->index(['shop_id', 'content_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_content_products');
    }
};

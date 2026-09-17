<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every question asked about a product, once: the next shopper who asks the same gets the
        // saved answer without a model. The question never carries contact details (refused in code).
        Schema::create('assistant_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('question_key', 64);
            $table->string('question', 500);
            $table->text('answer')->nullable();
            // answered, no_info (the product information does not say), out_of_scope
            $table->string('outcome', 20);
            // shown, or hidden by the team
            $table->string('status', 12)->default('shown');
            $table->unsignedSmallInteger('prompt_version');
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->unsignedInteger('asked_count')->default(1);
            $table->timestamp('last_asked_at');
            $table->timestamps();

            $table->unique(['product_id', 'question_key']);
            $table->index(['shop_id', 'product_id', 'outcome', 'asked_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_answers');
    }
};

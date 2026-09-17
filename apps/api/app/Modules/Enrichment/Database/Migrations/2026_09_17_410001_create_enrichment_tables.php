<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What agents may say about a branch of a shop's catalog. A new upload is a new version.
        Schema::create('enrichment_vocabularies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->string('root_category_external_id', 64);
            $table->json('definition');
            $table->string('definition_hash', 64);
            $table->string('author', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'key', 'version']);
        });

        // One file of agent requests. Filled by any runner: a download for an outside model today,
        // the provider's batch API later. Results come back into the same batch.
        Schema::create('enrichment_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('task', 40);
            $table->unsignedSmallInteger('review_tier')->default(0);
            $table->string('prompt_key', 60);
            $table->unsignedInteger('prompt_version');
            $table->string('prompt_hash', 64);
            // The exact instructions sent, so a later prompt change cannot alter what this batch meant.
            $table->longText('system_prompt');
            $table->foreignUlid('vocabulary_id')->nullable()->constrained('enrichment_vocabularies')->nullOnDelete();
            $table->json('scope')->nullable();
            $table->string('runner', 20)->default('external');
            $table->string('status', 24)->index();
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('model', 120)->nullable();
            $table->ulid('export_run_id')->nullable();
            $table->ulid('import_run_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('enrichment_batch_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('batch_id')->constrained('enrichment_batches')->cascadeOnDelete();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('custom_id', 120);
            $table->string('subject_type', 20);
            $table->ulid('subject_id');
            $table->string('input_hash', 64);
            $table->json('request');
            $table->json('context');
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->json('problems')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'custom_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        // One claim about a product or an article, with where it came from and who checked it.
        Schema::create('enrichment_facts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUlid('content_id')->nullable()->constrained('catalog_content')->cascadeOnDelete();
            $table->foreignUlid('vocabulary_id')->nullable()->constrained('enrichment_vocabularies')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('key', 80);
            $table->decimal('value_number', 20, 6)->nullable();
            $table->string('value_text', 255)->nullable();
            $table->string('unit', 12)->nullable();
            $table->text('quote')->nullable();
            // How it was found: "code+model" (code matched, a model confirmed) or "model" (a model alone).
            $table->string('origin', 20);
            $table->string('status', 24);
            $table->string('status_reason', 60)->nullable();
            $table->foreignUlid('batch_id')->nullable()->constrained('enrichment_batches')->nullOnDelete();
            $table->string('input_hash', 64);
            $table->string('model', 120)->nullable();
            $table->string('review_verdict', 12)->nullable();
            $table->string('review_model', 120)->nullable();
            $table->unsignedSmallInteger('review_tier')->default(0);
            $table->foreignUlid('review_batch_id')->nullable()->constrained('enrichment_batches')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['product_id', 'kind', 'key']);
            $table->index(['content_id', 'kind']);
        });

        // Superlatives, computed in code from approved facts: "the lightest of 9 cordless jigsaws".
        Schema::create('enrichment_rankings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('metric', 60);
            $table->string('direction', 3);
            $table->unsignedSmallInteger('rank');
            $table->boolean('tied')->default(false);
            $table->unsignedInteger('set_size');
            $table->string('set_key', 255);
            $table->json('set_facets');
            $table->decimal('value', 20, 6);
            $table->string('unit', 12)->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['shop_id', 'product_id']);
            $table->index(['shop_id', 'set_key', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_rankings');
        Schema::dropIfExists('enrichment_facts');
        Schema::dropIfExists('enrichment_batch_items');
        Schema::dropIfExists('enrichment_batches');
        Schema::dropIfExists('enrichment_vocabularies');
    }
};

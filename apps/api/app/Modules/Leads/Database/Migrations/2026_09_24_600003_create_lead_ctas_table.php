<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one page offers a reader, in the versions being tried.
 *
 * Several live at once on purpose. Which wording works on which page is not a thing anybody can
 * know by reading them, so the learning is given a choice and finds out — and the version that
 * was shown is recorded against every click, so the answer means something afterwards.
 *
 * The reviewer's score and its reasoning are kept beside the text rather than thrown away, so a
 * version can be argued with later, and so the reviewer itself can be measured against what
 * readers actually did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_ctas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('page_type', 12);
            $table->string('page_external_id', 64);

            // subject, question, audience, points, offer — which shape this one takes.
            $table->string('variant', 20);
            $table->string('headline', 200);
            $table->string('body', 300);

            // code or model: who wrote it.
            $table->string('source', 10);
            $table->string('model', 120)->nullable();

            // What the reviewer made of it, and why. Null until a model has been asked.
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('review')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamp('composed_at');
            $table->timestamps();

            $table->unique(['shop_id', 'page_type', 'page_external_id', 'variant']);
            $table->index(['shop_id', 'page_type', 'page_external_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_ctas');
    }
};

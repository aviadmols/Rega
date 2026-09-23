<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reading rules a whole trade shares, so a shop opened tomorrow starts where the others got
 * to instead of from the defaults.
 *
 * A version is only ever added, exactly like a shop's own rules. Nothing here belongs to any one
 * shop: a marker reaches this table only once several shops arrived at it separately, and it
 * carries no product, no price and no shopper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_content_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('vertical', 40)->index();
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->string('rules_hash', 64);
            $table->string('author', 60)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['vertical', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_content_templates');
    }
};

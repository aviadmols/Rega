<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How well each widget section does, computed nightly from events and orders:
        //   module       one section across the whole shop ("complement")
        //   page_module  one section on one product or article page
        //   related      one product shown inside a section on one page
        // Empty strings, not nulls, fill the keys a scope does not use, so the unique index holds
        // on every database.
        Schema::create('analytics_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('scope', 12);
            $table->string('candidate', 80);
            $table->string('page_type', 20)->default('');
            $table->string('page_external_id', 64)->default('');
            $table->string('related_external_id', 64)->default('');
            $table->unsignedInteger('exposures')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('adds')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->unsignedInteger('value')->default(0);
            $table->decimal('score', 10, 5)->default(0);
            $table->timestamp('computed_at');

            $table->unique(['shop_id', 'scope', 'candidate', 'page_type', 'page_external_id', 'related_external_id'], 'analytics_scores_unique');
            $table->index(['shop_id', 'page_type', 'page_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_scores');
    }
};

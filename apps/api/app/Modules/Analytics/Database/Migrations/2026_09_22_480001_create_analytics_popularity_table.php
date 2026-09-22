<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How often each product was added to the cart and ordered in the last weeks, computed
        // nightly (ComputePopularity). The widget shows the counts and a "popular" mark from here,
        // never by counting events on page load.
        Schema::create('analytics_popularity', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('product_external_id', 64);
            $table->unsignedInteger('adds')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('rank')->default(0);
            $table->boolean('popular')->default(false);
            $table->unsignedSmallInteger('window_days');
            $table->timestamp('computed_at');

            $table->unique(['shop_id', 'product_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_popularity');
    }
};

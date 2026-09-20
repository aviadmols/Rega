<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the store team decided about one page of the widget: a product pinned into a section
        // (shown first, never dropped by learning), a product hidden from it, or a whole section
        // hidden (item '' ). Overrides what code built and what learning would do.
        Schema::create('widget_curations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('page_type', 10);
            $table->string('page_external_id', 64);
            $table->string('candidate', 80);
            $table->string('item_external_id', 64)->default('');
            $table->string('action', 10);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'page_type', 'page_external_id', 'candidate', 'item_external_id'], 'widget_curations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_curations');
    }
};

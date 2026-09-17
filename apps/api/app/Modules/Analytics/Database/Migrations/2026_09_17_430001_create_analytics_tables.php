<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What visitors did with the widget, as the event spec defines it. No free text, no
        // query strings, visitors only as a hash salted per shop.
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('event_id', 64);
            $table->string('type', 20);
            $table->string('page_type', 20);
            $table->string('page_path', 512);
            $table->string('product_external_id', 64)->nullable();
            $table->string('content_external_id', 64)->nullable();
            $table->string('item_external_id', 64)->nullable();
            $table->string('model', 30)->nullable();
            $table->string('candidate_id', 80)->nullable();
            $table->string('slot', 10)->nullable();
            $table->string('source', 10)->nullable();
            $table->string('result', 20)->nullable();
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->string('visitor_hash', 64);
            $table->string('session_id', 64);
            $table->boolean('preview')->default(false);
            $table->boolean('holdout')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['shop_id', 'event_id']);
            $table->index(['shop_id', 'occurred_at']);
            $table->index(['shop_id', 'type', 'occurred_at']);
            $table->index(['shop_id', 'visitor_hash', 'occurred_at']);
        });

        // Orders as the store's plugin reports them: totals and product IDs, never the customer.
        Schema::create('analytics_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('order_ref', 64);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3);
            $table->unsignedSmallInteger('items_count');
            $table->json('items');
            $table->string('visitor_hash', 64)->nullable();
            $table->boolean('assisted')->default(false);
            $table->decimal('attributed_total', 12, 2)->default(0);
            $table->json('attributed_items')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();

            $table->unique(['shop_id', 'order_ref']);
            $table->index(['shop_id', 'ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_orders');
        Schema::dropIfExists('analytics_events');
    }
};

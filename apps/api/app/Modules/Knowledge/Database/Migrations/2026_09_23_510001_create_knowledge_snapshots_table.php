<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was known about a shop on one day.
 *
 * Every other table in the system holds the present: the facts as they are now, the scores as
 * they were last computed. None of them can answer "what changed". A snapshot a night can, and
 * the difference between two of them is the whole of "what the system learned this week" —
 * written once, read by both screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('taken_on');

            // How much of what was scanned the system has something to say about.
            $table->json('coverage');
            // When each step last ran, so an old reading can be told from a fresh one.
            $table->json('freshness');
            // What is missing, and what would close it.
            $table->json('gaps');
            // What the shopper is shown, per page type, in order.
            $table->json('arrangement');
            // What the learning is being judged on, and whether each signal is alive.
            $table->json('signals');

            $table->timestamps();
            $table->unique(['shop_id', 'taken_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_snapshots');
    }
};

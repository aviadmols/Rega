<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the learning helped, measured against the shoppers it was kept away from.
 *
 * One row a week per shop. Both sides counted the same way over the same window, so the answer
 * is a comparison and not an impression — and a verdict that is allowed to be "too early", which
 * is the honest answer most weeks a shop is young.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_measurements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('taken_on');
            $table->unsignedSmallInteger('window_days');

            // exposures, opens, clicks, adds, orders — for each side.
            $table->json('learned');
            $table->json('control');
            // Per measure: the two rates, the difference, and whether it is worth believing.
            $table->json('effect');
            // helped, hurt, no_difference, too_early, no_control
            $table->string('verdict', 20)->index();

            $table->timestamps();
            $table->unique(['shop_id', 'taken_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_measurements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a panel tends to do across the shops of one trade.
 *
 * A shop with no traffic of its own has no reason to show the panels in the order they happen to
 * be written in the code. Other shops of its trade have already been watched for months. These
 * are rates only — how often a panel was opened after being seen — with no shop, no product and
 * no person in them, and they are the whole of what a new shop inherits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_priors', function (Blueprint $table): void {
            $table->id();
            $table->string('vertical', 40);
            $table->string('candidate', 40);
            $table->unsignedInteger('shops');
            $table->unsignedBigInteger('exposures');
            $table->unsignedBigInteger('opens');
            $table->unsignedBigInteger('clicks');
            $table->decimal('score', 8, 6);
            $table->timestamp('computed_at');

            $table->unique(['vertical', 'candidate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_priors');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the reviewer thought, kept so the reviewer can be judged.
 *
 * A second model scoring a first is only worth having if its scores mean something, and the only
 * way to know that is to write them down and see what readers did afterwards. A critic whose
 * eighties are clicked no more often than its seventies is not a critic, and without this table
 * nobody would ever find out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('cta_id')->constrained('lead_ctas')->cascadeOnDelete();

            $table->string('writer', 120);
            $table->string('reviewer', 120);
            $table->unsignedTinyInteger('score');
            $table->json('reasons');

            // Filled in later from what readers did, so the score can be checked against them.
            $table->unsignedInteger('exposures')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->timestamp('measured_at')->nullable();

            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['shop_id', 'reviewer', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_reviews');
    }
};

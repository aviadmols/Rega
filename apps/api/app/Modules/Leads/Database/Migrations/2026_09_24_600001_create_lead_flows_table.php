<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a shop wants from a reader, and what it is willing to ask for it.
 *
 * The one thing here nobody can work out from the site: whether a shop wants a phone call booked
 * or an email address for a guide, what it is offering in return, and which fields it is prepared
 * to make somebody type. That is a business decision, so it is asked once and then obeyed.
 *
 * A version is added rather than edited, like every other rule in the platform, so a lead can
 * always be read against the wording the person actually agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_flows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedInteger('version');

            // advice, quote, signup or download — what the reader is being offered.
            $table->string('goal', 20);
            $table->string('offer', 200);
            // The fields to collect, in order: [{key, type, label, required}]
            $table->json('fields');
            $table->text('consent');
            // What the team says it will do, so the thank-you can promise it: "within a day".
            $table->string('promise', 120)->nullable();

            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'version']);
            $table->index(['shop_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_flows');
    }
};

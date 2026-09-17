<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Null for system-wide work, such as checking an AI provider key.
            $table->foreignUlid('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('runs')->nullOnDelete();

            // "{module}.{name}", e.g. connections.store_checker / connections.test
            $table->string('agent', 80)->index();
            $table->string('action', 80)->index();
            $table->string('status', 20)->index();
            $table->string('trigger', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // A translation key and its parameters, so each viewer reads the result in their language.
            $table->string('summary_key', 160)->nullable();
            $table->json('summary_params')->nullable();
            $table->text('error')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();

            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 20)->unique();
            // Encrypted with APP_KEY.
            $table->text('api_key');
            $table->string('key_hint', 32)->nullable();
            $table->string('status', 20)->default('untested');
            $table->string('last_error_code', 40)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            // The models this key can use, from the provider's own list, newest first.
            $table->json('models')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};

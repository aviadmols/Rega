<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('name', 100);
            // Shown in the admin so a key can be recognised. The secret itself is never stored.
            $table->string('prefix', 16);
            $table->char('hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_api_keys');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Someone asked a question the assistant could not answer and left a way to be told the
        // answer. The contact itself stays on the identity, encrypted; this is only the asking.
        Schema::create('shopper_callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('identity_id')->constrained('shopper_identities')->cascadeOnDelete();
            $table->string('question', 500);
            $table->string('page_type', 16);
            $table->string('page_id', 64);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'answered_at', 'created_at']);
            $table->index(['shop_id', 'page_type', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopper_callbacks');
    }
};

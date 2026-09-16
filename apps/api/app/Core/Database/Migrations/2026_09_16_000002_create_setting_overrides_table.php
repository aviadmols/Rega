<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->string('scope', 40);
            $table->json('value');
            $table->timestamps();

            $table->unique(['key', 'scope']);
            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_overrides');
    }
};

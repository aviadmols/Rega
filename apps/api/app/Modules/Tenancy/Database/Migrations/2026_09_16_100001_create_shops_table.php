<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('platform', 20);
            $table->string('domain')->unique();
            // The language visitors see. Independent of the admin language each user picks.
            $table->string('content_locale', 10)->default('he');
            $table->char('currency', 3)->default('ILS');
            $table->string('timezone', 64)->default('Asia/Jerusalem');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};

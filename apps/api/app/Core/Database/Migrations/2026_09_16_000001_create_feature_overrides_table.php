<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            // "*" is the global value; anything else is a shop id. No foreign key: the kernel
            // does not know what a shop is. Tenancy purges a shop's rows when it is deleted.
            $table->string('scope', 40);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['key', 'scope']);
            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_overrides');
    }
};

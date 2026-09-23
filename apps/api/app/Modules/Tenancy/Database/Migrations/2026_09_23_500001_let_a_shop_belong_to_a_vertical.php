<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's trade, so what one shop learns can reach the next one of its kind.
 *
 * It is read from the catalogue rather than asked for, because a person filling in a form is a
 * person who has to know the answer. Locked means someone said otherwise, and the reading stops
 * arguing with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('vertical', 40)->nullable()->index();
            $table->unsignedTinyInteger('vertical_confidence')->default(0);
            $table->boolean('vertical_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['vertical', 'vertical_confidence', 'vertical_locked']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_answers', function (Blueprint $table) {
            // store: the store's product information answered it; general: knowledge about products like it
            $table->string('source', 10)->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};

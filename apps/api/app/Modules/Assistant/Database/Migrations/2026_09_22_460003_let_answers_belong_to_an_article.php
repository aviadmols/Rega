<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A question can now be asked on a guide as well as on a product: "sum this up for me".
        // One of the two columns holds the page it was asked on, never both.
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->foreignUlid('content_id')->nullable()->after('product_id')->constrained('catalog_content')->cascadeOnDelete();
        });

        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->foreignUlid('product_id')->nullable()->change();
        });

        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->unique(['content_id', 'question_key']);
            $table->index(['shop_id', 'content_id', 'outcome', 'asked_count']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->dropUnique(['content_id', 'question_key']);
            $table->dropIndex(['shop_id', 'content_id', 'outcome', 'asked_count']);
            $table->dropConstrainedForeignId('content_id');
        });
    }
};

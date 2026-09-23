<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The marker words the article reader looks for, per shop, versioned. A version is only
        // ever added; the one in force is the active one, and going back is making an older one
        // active again.
        Schema::create('enrichment_content_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->string('rules_hash', 64);
            $table->string('author', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'version']);
            $table->index(['shop_id', 'active']);
        });

        // What an audit found and what it proposes doing about it. Nothing here changes how the
        // reader behaves until a person publishes it, which is what makes it reviewable.
        Schema::create('enrichment_rule_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedInteger('from_version');
            // pending, approved (the reviewer agreed), rejected (it did not), published, discarded
            $table->string('status', 12)->default('pending');
            $table->json('sampled');
            $table->json('findings');
            $table->json('proposed')->nullable();
            $table->json('review')->nullable();
            $table->string('summary', 500)->nullable();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'created_at']);
        });

        // Which articles were looked at, so the next audit looks at different ones.
        Schema::table('catalog_content', function (Blueprint $table) {
            $table->timestamp('audited_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_content', fn (Blueprint $table) => $table->dropColumn('audited_at'));
        Schema::dropIfExists('enrichment_rule_proposals');
        Schema::dropIfExists('enrichment_content_rules');
    }
};

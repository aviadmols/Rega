<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody who asked to be contacted.
 *
 * The contact itself is encrypted and looked up by a hash salted per shop, exactly as a shopper's
 * is, so two shops cannot discover they share a customer and a database that leaks reveals a list
 * of hashes. The wording they agreed to is copied in rather than referenced, because a consent
 * that can be edited afterwards is not a consent.
 *
 * Everything on this row is either the person's own answer or what the page was; nothing about
 * how the model behaved is kept here, and nothing here ever reaches a model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('flow_id')->constrained('lead_flows')->cascadeOnDelete();

            // Where they were when they asked, so a shop can see which page earns its leads.
            $table->string('page_type', 12);
            $table->string('page_external_id', 64);
            $table->string('cta_id', 40)->nullable();

            // How to reach them: the hash finds it again, the encrypted value is read by a person,
            // and the mask is what a screen shows without anybody choosing to look.
            $table->string('channel', 10);
            $table->string('contact_hash', 64);
            $table->text('contact');
            $table->string('contact_masked', 40);

            // Their other answers, keyed by the flow's field keys. Encrypted: a name is personal,
            // and "how much are you looking to invest" is more so.
            $table->text('answers')->nullable();

            $table->timestamp('consented_at');
            $table->text('consent_wording');

            // What code made of it: complete, reachable, engaged. Never a model's opinion.
            $table->unsignedTinyInteger('quality');
            $table->string('status', 12)->default('new');

            $table->string('visitor_hash', 64)->nullable();
            $table->foreignId('seen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'created_at']);
            $table->index(['shop_id', 'contact_hash']);
            $table->index(['shop_id', 'page_type', 'page_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shopper who left a phone or an email in the widget. The contact itself is encrypted;
        // the hash is what lookups use, so the same person is recognised without reading it.
        Schema::create('shopper_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('contact_hash', 64);
            $table->text('contact');
            // "05X-XXX-4567", for lists and logs that must not carry the whole number.
            $table->string('contact_masked', 40);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'contact_hash']);
        });

        // Which anonymous visitors are that person. A link is "verified" only when the person
        // proved the contact is theirs with a code; browsing follows them across devices only
        // between verified links, so typing someone else's phone shows nothing of theirs.
        Schema::create('shopper_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->foreignId('identity_id')->constrained('shopper_identities')->cascadeOnDelete();
            $table->boolean('verified')->default(false);
            $table->timestamp('linked_at');

            $table->unique(['shop_id', 'visitor_hash']);
            $table->index(['shop_id', 'identity_id']);
        });

        // A code waiting to be typed back. Kept apart from the identity so an unfinished sign-up
        // leaves nothing behind, and pruned every night.
        Schema::create('shopper_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->string('channel', 10);
            $table->string('contact_hash', 64);
            $table->text('contact');
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'visitor_hash']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopper_verifications');
        Schema::dropIfExists('shopper_visitors');
        Schema::dropIfExists('shopper_identities');
    }
};

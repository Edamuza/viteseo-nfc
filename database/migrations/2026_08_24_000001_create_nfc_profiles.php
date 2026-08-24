<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile-mode payload, 1-to-1 with a card.
 *
 * Separate from `cards` for the same reason `nfc_contact_profiles` is: these
 * columns are null for most cards, and the cards row is read on every public
 * request. Separate from `nfc_contact_profiles` because the two modes describe
 * different things — a vCard's structured name and address versus a profile
 * page's headline and tagline — and merging them would leave both half-empty.
 *
 * The accounts list is NOT here: Profile mode reads the same `nfc_links` rows
 * that Links mode does, so switching between the two modes is lossless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->unique()->constrained('cards')->cascadeOnDelete();

            $table->string('display_name', 120)->nullable();
            $table->string('headline', 200)->nullable();
            $table->string('username', 60)->nullable();     // plain text, never linked
            $table->string('location', 100)->nullable();
            $table->string('tagline', 200)->nullable();

            $table->string('avatar_path', 255)->nullable(); // server-generated name only

            // Admin-authored welcome panel. The columns land now so the public
            // view and the reset path have a stable shape; the admin form that
            // fills them arrives in increment 5.
            $table->string('welcome_eyebrow', 60)->nullable();
            $table->string('welcome_heading', 200)->nullable();
            $table->text('welcome_body')->nullable();
            $table->string('welcome_cta_label', 60)->nullable();
            $table->string('welcome_cta_url', 500)->nullable();

            // Derived from the avatar by DominantColor (increment 5), never
            // submitted. Stored rather than computed per request: decoding an
            // image on every public page view would be absurd for a value that
            // changes only when the photo does.
            $table->string('brand_color', 7)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_profiles');
    }
};

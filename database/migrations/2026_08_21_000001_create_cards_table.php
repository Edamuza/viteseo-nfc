<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();

            // Human-friendly sequential label (CARD-0001). NEVER used in public URLs.
            $table->string('card_number', 32)->unique();

            // Cryptographically random, non-enumerable. This is what public URLs use.
            $table->string('public_id', 32)->unique();

            // Verification hash (bcrypt) + admin-recoverable ciphertext.
            // Plaintext PIN is never stored.
            $table->string('activation_pin_hash');
            $table->text('activation_pin_encrypted');

            // QR: exactly ONE destination, ever. Null = unconfigured.
            $table->text('qr_destination')->nullable();
            $table->timestamp('qr_configured_at')->nullable();

            // NFC: multiple links live in nfc_links. This marks "setup completed".
            $table->timestamp('nfc_configured_at')->nullable();
            $table->string('nfc_page_title', 120)->nullable();
            $table->string('nfc_page_subtitle', 200)->nullable();
            $table->string('nfc_logo_path')->nullable();

            $table->timestamps();

            $table->index('qr_configured_at');
            $table->index('nfc_configured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};

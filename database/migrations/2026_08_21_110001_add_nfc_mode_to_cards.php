<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the NFC mode selector plus the small payloads for the phone and email
 * modes.
 *
 * Phone/email carry one and three fields respectively, so they live on `cards`.
 * The contact card has ~28 fields and gets its own 1-to-1 table instead.
 *
 * Default 'links' means every existing card keeps its current behaviour exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('nfc_mode', 20)->default('links')->after('nfc_configured_at')->index();

            // Phone mode
            $table->string('nfc_phone_number', 32)->nullable()->after('nfc_logo_path');

            // Email mode
            $table->string('nfc_email_address', 190)->nullable()->after('nfc_phone_number');
            $table->string('nfc_email_subject', 200)->nullable()->after('nfc_email_address');
            $table->text('nfc_email_body')->nullable()->after('nfc_email_subject');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['nfc_mode']);
            $table->dropColumn([
                'nfc_mode', 'nfc_phone_number',
                'nfc_email_address', 'nfc_email_subject', 'nfc_email_body',
            ]);
        });
    }
};

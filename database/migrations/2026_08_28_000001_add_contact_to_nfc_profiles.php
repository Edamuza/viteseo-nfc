<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A phone number and an email address on the profile page.
 *
 * Separate columns from the ones on `nfc_contact_profiles`, even though both
 * hold a number and an address, because the two modes are independent: a card
 * switched from Contact to Profile and back must find each mode's data exactly
 * as it left it, and sharing one column would make either mode's edit silently
 * rewrite the other's.
 *
 * Lengths match the contact table's, so the same SafePhoneNumber rule and the
 * same 190-character email ceiling apply on both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_profiles', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('location');
            $table->string('email', 190)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_profiles', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email']);
        });
    }
};

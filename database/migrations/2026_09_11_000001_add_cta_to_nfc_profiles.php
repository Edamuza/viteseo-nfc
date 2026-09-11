<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own button beside Share on the profile page.
 *
 * Separate from welcome_cta_*: that button sits inside the Advanced details
 * panel and only an administrator writes it. This one is the owner's, sits
 * on the face of the page, and is painted in the page's own colour.
 *
 * The label is short on purpose: it shares a row with Share on a phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_profiles', function (Blueprint $table) {
            $table->string('cta_label', 40)->nullable()->after('email');
            $table->string('cta_url', 500)->nullable()->after('cta_label');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_profiles', function (Blueprint $table) {
            $table->dropColumn(['cta_label', 'cta_url']);
        });
    }
};

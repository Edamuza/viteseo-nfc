<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own cover photo, behind the identity card at the top of the page.
 *
 * Lives on `nfc_profiles` beside `avatar_path` and follows the same rules: it
 * is a server-generated name only (never mass-assigned), and it is served from
 * a guessable /storage/... URL, so it is paired with a forget() in
 * CardResetService exactly the way the avatar is.
 *
 * The cover is NOT what drives the brand colour. That stays derived from the
 * avatar (DominantColor), because the cover is a photograph with no fixed
 * subject, while the avatar is the owner's own face. A page background derived
 * from a random landscape would be no more useful than the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_profiles', function (Blueprint $table) {
            $table->string('cover_path', 255)->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_profiles', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
};

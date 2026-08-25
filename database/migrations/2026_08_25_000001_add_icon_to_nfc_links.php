<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfc_links', function (Blueprint $table): void {
            // The author's chosen logo for the link: a LinkPlatform key, 'web'
            // for the plain globe, or 'none' for label-only. NULL means "no
            // choice made" and keeps the pre-existing behaviour of detecting
            // the platform from the URL.
            $table->string('icon', 20)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('nfc_links', function (Blueprint $table): void {
            $table->dropColumn('icon');
        });
    }
};

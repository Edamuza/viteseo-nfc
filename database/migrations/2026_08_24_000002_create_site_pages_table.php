<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->timestamps();
        });

        $now = now();

        DB::table('site_pages')->insert([
            [
                'key' => 'about',
                'title' => 'What is this?',
                'body' => "This page belongs to a ViteSEO NFC business card. Tapping the card on a phone, or scanning its printed QR code, opens this page.\n\nThe card itself holds only a permanent web address. Because the details live here rather than on the chip, the cardholder can update their links, photo and contact information at any time without reprinting anything.\n\nViteSEO Digital Advertising OPC produces and manages these cards.",
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'terms',
                'title' => 'Terms of Service',
                'body' => "Links shown on a card page are supplied by the cardholder. ViteSEO does not endorse or verify third-party destinations.\n\nCardholders are responsible for keeping their profile information accurate and lawful.",
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'privacy',
                'title' => 'Privacy Policy',
                'body' => "Card pages carry no advertising trackers and are served with no-store and noindex headers.\n\nProfile details are displayed only to people who open the card's permanent public URL.",
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
    }
};

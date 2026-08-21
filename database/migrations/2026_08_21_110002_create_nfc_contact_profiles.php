<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital contact card data, 1-to-1 with a card.
 *
 * Kept off the `cards` table deliberately: ~28 mostly-null columns would bloat
 * every card row and every card query for a feature only some cards use.
 *
 * The row survives mode switches. Switching to Links and back must not lose the
 * contact details, so deletion is only ever explicit (admin "Delete contact details").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_contact_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->unique()->constrained('cards')->cascadeOnDelete();

            // Personal
            $table->string('first_name', 80)->nullable();
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('suffix', 30)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('company', 150)->nullable();

            // Phone
            $table->string('mobile_phone', 32)->nullable();
            $table->string('work_phone', 32)->nullable();
            $table->string('landline', 32)->nullable();
            $table->string('other_phone', 32)->nullable();

            // Email
            $table->string('personal_email', 190)->nullable();
            $table->string('work_email', 190)->nullable();

            // Web
            $table->string('website', 500)->nullable();

            // Address
            $table->string('street', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 100)->nullable();

            // Other
            $table->date('birthday')->nullable();
            $table->string('related_name', 120)->nullable();
            $table->text('notes')->nullable();

            // Social
            $table->string('facebook_url', 500)->nullable();
            $table->string('instagram_url', 500)->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->string('tiktok_url', 500)->nullable();
            $table->string('x_url', 500)->nullable();
            $table->string('youtube_url', 500)->nullable();
            $table->string('other_social_url', 500)->nullable();

            // Server-generated relative path only - the original filename is never trusted.
            $table->string('photo_path', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_contact_profiles');
    }
};

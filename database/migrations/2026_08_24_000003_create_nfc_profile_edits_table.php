<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_profile_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['card_id', 'created_at'], 'nfc_profile_edits_card_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_profile_edits');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The hot path is "active links for this card, in order".
            $table->index(['card_id', 'is_active', 'sort_order'], 'nfc_links_card_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_links');
    }
};

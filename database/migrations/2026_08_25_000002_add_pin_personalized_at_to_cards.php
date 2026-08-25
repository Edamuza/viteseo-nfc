<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            // Set when the owner replaces the universal activation PIN with
            // their own during setup. NULL means the card still answers to
            // the universal PIN and setup will demand a personal one.
            $table->timestamp('pin_personalized_at')->nullable()->after('activation_pin_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn('pin_personalized_at');
        });
    }
};

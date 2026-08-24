<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sticker_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('front_path', 255);
            $table->string('back_path', 255);
            $table->unsignedInteger('qr_x');
            $table->unsignedInteger('qr_y');
            $table->unsignedInteger('qr_size');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        $front = $this->storeBundled('front.png');
        $back = $this->storeBundled('back.png');
        $now = now();

        DB::table('sticker_templates')->insert([
            'name' => 'Default',
            'front_path' => $front,
            'back_path' => $back,
            'qr_x' => 106,
            'qr_y' => 77,
            'qr_size' => 426,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_templates');
    }

    private function storeBundled(string $filename): string
    {
        $bytes = file_get_contents(resource_path('images/card-review-sticker/'.$filename));
        if ($bytes === false) {
            throw new \RuntimeException('Could not read bundled sticker artwork: '.$filename);
        }

        $path = 'sticker-templates/'.hash('sha256', $filename.$bytes).'.png';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
};

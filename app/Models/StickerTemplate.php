<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StickerTemplate extends Model
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return [
            'qr_x' => 'integer',
            'qr_y' => 'integer',
            'qr_size' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    public static function restoreBundledFiles(): void
    {
        $template = static::query()->where('name', 'Default')->first();
        if ($template === null) {
            return;
        }

        foreach (['front_path' => 'front.png', 'back_path' => 'back.png'] as $attribute => $filename) {
            $bytes = file_get_contents(resource_path('images/card-review-sticker/'.$filename));
            if (is_string($bytes)) {
                Storage::disk('public')->put($template->{$attribute}, $bytes);
            }
        }
    }
}

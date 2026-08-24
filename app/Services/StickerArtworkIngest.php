<?php

namespace App\Services;

use App\Exceptions\UnreadableImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StickerArtworkIngest
{
    public function store(UploadedFile $file): string
    {
        $raw = @file_get_contents($file->getRealPath());
        if ($raw === false) {
            throw new UnreadableImageException('Could not read the uploaded image.');
        }

        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            throw new UnreadableImageException('That file is not a readable image.');
        }

        $canvas = imagecreatetruecolor(CardReviewStickerService::WIDTH, CardReviewStickerService::HEIGHT);
        if ($canvas === false) {
            unset($source);
            throw new UnreadableImageException('Could not allocate the sticker canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, CardReviewStickerService::WIDTH - 1, CardReviewStickerService::HEIGHT - 1, $transparent);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            CardReviewStickerService::WIDTH,
            CardReviewStickerService::HEIGHT,
            imagesx($source),
            imagesy($source)
        );
        unset($source);

        ob_start();
        try {
            if (! imagepng($canvas, null, 9)) {
                throw new UnreadableImageException('Could not encode the processed image.');
            }
            $clean = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            unset($canvas);
        }

        if ($clean === '') {
            throw new UnreadableImageException('Could not encode the processed image.');
        }

        $path = 'sticker-templates/'.bin2hex(random_bytes(16)).'.png';
        if (! Storage::disk('public')->put($path, $clean)) {
            throw new UnreadableImageException('Could not store the processed image.');
        }

        return $path;
    }
}

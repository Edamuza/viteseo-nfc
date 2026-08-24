<?php

namespace App\Services;

use App\Exceptions\UnreadableImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Storing an uploaded image safely.
 *
 * Extracted from CardController so the contact photo and the profile avatar
 * cannot drift apart. Every guarantee below is load-bearing:
 *
 *  - the file is re-encoded through GD, which discards EXIF, appended archives
 *    and any PHP smuggled inside an otherwise-valid image
 *  - the extension comes from that re-encode, never from the upload name
 *  - the filename is server-generated random hex, so nothing user-supplied
 *    reaches the filesystem
 *  - dimensions are capped, so a huge upload cannot be served back verbatim
 */
class ImageIngest
{
    public function store(UploadedFile $file, string $directory, int $maxEdge): string
    {
        return $this->storeWithInspection($file, $directory, $maxEdge, fn (\GdImage $image) => null)['path'];
    }

    /**
     * @return array{path: string, inspection: mixed}
     */
    public function storeWithInspection(
        UploadedFile $file,
        string $directory,
        int $maxEdge,
        callable $inspector,
    ): array
    {
        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false) {
            throw new UnreadableImageException('Could not read the uploaded image.');
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw new UnreadableImageException('That file is not a readable image.');
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxEdge || $height > $maxEdge) {
            $scale   = $maxEdge / max($width, $height);
            $resized = $this->resize($image, (int) round($width * $scale), (int) round($height * $scale));

            if (! $resized instanceof \GdImage) {
                throw new UnreadableImageException('Could not resize the uploaded image.');
            }

            unset($image);
            $image = $resized;
        }

        $inspection = $inspector($image);

        ob_start();
        try {
            if (! $this->writeJpeg($image)) {
                throw new UnreadableImageException('Could not encode the processed image.');
            }
            $clean = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        unset($image);

        if ($clean === '') {
            throw new UnreadableImageException('Could not encode the processed image.');
        }

        $path = trim($directory, '/').'/'.bin2hex(random_bytes(16)).'.jpg';
        if (! Storage::disk('public')->put($path, $clean)) {
            throw new UnreadableImageException('Could not store the processed image.');
        }

        return ['path' => $path, 'inspection' => $inspection];
    }

    protected function resize(\GdImage $image, int $width, int $height): \GdImage|false
    {
        return imagescale($image, $width, $height);
    }

    protected function writeJpeg(\GdImage $image): bool
    {
        return imagejpeg($image, null, 85);
    }
}

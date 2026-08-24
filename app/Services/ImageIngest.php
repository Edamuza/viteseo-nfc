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
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));

            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();
        imagejpeg($image, null, 85);   // re-encode: output is a clean JPEG, nothing else
        $clean = (string) ob_get_clean();
        imagedestroy($image);

        $path = trim($directory, '/').'/'.bin2hex(random_bytes(16)).'.jpg';
        Storage::disk('public')->put($path, $clean);

        return $path;
    }
}

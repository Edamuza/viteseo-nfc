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

        $this->refuseIfTooBigToDecode($raw);

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw new UnreadableImageException('That file is not a readable image.');
        }

        $image = $this->uprightForCamera($image, $file->getRealPath());

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

    /**
     * A decoded image costs 4 bytes a pixel however small the file was.
     *
     * A 12-megapixel phone photo is ~46 MB in GD, which fits; a deliberately
     * crafted 100-megapixel PNG compresses to almost nothing and would take
     * 400 MB, which does not. The header is read on its own - cheap, and the
     * only way to know the cost BEFORE paying it - and the budget is raised
     * for this request alone rather than for the whole process.
     */
    private function refuseIfTooBigToDecode(string $raw): void
    {
        $size = @getimagesizefromstring($raw);

        if (! is_array($size)) {
            return; // Not readable as an image; imagecreatefromstring says so next.
        }

        $needed = (int) ($size[0] * $size[1] * 4 * 1.6); // pixels, plus room to resize

        if ($needed > 384 * 1024 * 1024) {
            throw new UnreadableImageException(
                'That image has too many pixels to process. Please use one under 8000 pixels on its longest side.'
            );
        }

        $limit = $this->memoryLimitInBytes();

        if ($limit > 0 && $needed > $limit - memory_get_usage(true)) {
            @ini_set('memory_limit', (string) ($needed + 64 * 1024 * 1024));
        }
    }

    private function memoryLimitInBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Turn a camera photo the way its owner held the phone.
     *
     * A phone stores the sensor's landscape frame and records the rotation in
     * EXIF; browsers apply it, GD does not. Re-encoding drops the tag - which
     * is deliberate, EXIF carries GPS - so without this the stored copy keeps
     * the sideways pixels and loses the note that says which way is up.
     */
    private function uprightForCamera(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;

        $turned = match ($orientation) {
            3 => @imagerotate($image, 180, 0),
            6 => @imagerotate($image, -90, 0),
            8 => @imagerotate($image, 90, 0),
            default => null,
        };

        return $turned instanceof \GdImage ? $turned : $image;
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

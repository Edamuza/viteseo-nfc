<?php

namespace App\Services;

use App\Models\Card;
use App\Models\NfcProfile;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * QR codes are rendered on demand from the card's permanent URL. Nothing is
 * cached to disk and no third-party QR service is involved, so a card's QR can
 * never drift from its public_id.
 */
class QrCodeService
{
    public const PNG_SIZE = 1200;   // print/design friendly

    public const SVG_SIZE = 1000;

    public const QUIET_ZONE = 4;    // modules; the spec minimum for reliable scanning

    /**
     * Level M (~15% recovery). High enough to survive print and handling wear,
     * low enough to keep the module count — and therefore the printed dot size —
     * comfortable. No logo is embedded in V1, so we do not need Q or H.
     */
    private function eccLevel(): ErrorCorrectionLevel
    {
        return ErrorCorrectionLevel::M();
    }

    public function svgForCard(Card $card): string
    {
        return $this->svg($card->qrUrl());
    }

    public function pngForCard(Card $card, int $maxSize = self::PNG_SIZE): string
    {
        return $this->png($card->qrUrl(), $maxSize);
    }

    public function pngForProfile(Card $card, NfcProfile $profile, int $maxSize = self::PNG_SIZE): string
    {
        $png = $this->renderPng($card->nfcUrl(), $maxSize, ErrorCorrectionLevel::H());
        $image = imagecreatefromstring($png);

        if (! $image instanceof \GdImage) {
            return $png;
        }

        $tileSize = max(1, (int) floor(imagesx($image) * .22));
        $border   = max(2, (int) floor($tileSize * .06));
        $inner    = max(1, $tileSize - ($border * 2));
        $tile     = imagecreatetruecolor($tileSize, $tileSize);
        $white    = imagecolorallocate($tile, 255, 255, 255);
        imagefilledrectangle($tile, 0, 0, $tileSize - 1, $tileSize - 1, $white);

        if (! $this->copyAvatar($tile, $profile, $border, $inner)) {
            $this->drawInitials($tile, $profile, $border, $inner);
        }

        $offset = intdiv(imagesx($image) - $tileSize, 2);
        imagecopy($image, $tile, $offset, $offset, 0, 0, $tileSize, $tileSize);

        $result = $this->encodePng($image);
        unset($tile, $image);

        return $result;
    }

    public function svg(string $payload): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(self::SVG_SIZE, self::QUIET_ZONE),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($payload, Encoder::DEFAULT_BYTE_MODE_ECODING, $this->eccLevel());
    }

    /**
     * Rendered with GD directly rather than through an image back-end, so the
     * only hard dependency is ext-gd (imagick is not installed on PHP 8.4 here).
     */
    public function png(string $payload, int $maxSize = self::PNG_SIZE): string
    {
        return $this->renderPng($payload, $maxSize, $this->eccLevel());
    }

    private function renderPng(string $payload, int $maxSize, ErrorCorrectionLevel $level): string
    {
        if ($maxSize < 1) {
            throw new InvalidArgumentException('QR maximum size must be positive.');
        }

        $matrix  = Encoder::encode($payload, $level, Encoder::DEFAULT_BYTE_MODE_ECODING)->getMatrix();
        $modules = $matrix->getWidth();
        $total   = $modules + (self::QUIET_ZONE * 2);

        if ($maxSize < $total) {
            throw new InvalidArgumentException('QR maximum size is too small for one pixel per module.');
        }

        // Integer scale keeps every module pixel-crisp; no resampling blur.
        $scale = (int) floor($maxSize / $total);
        $size  = $total * $scale;

        $img   = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $px = ($x + self::QUIET_ZONE) * $scale;
                    $py = ($y + self::QUIET_ZONE) * $scale;
                    imagefilledrectangle($img, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }

        $data = $this->encodePng($img);
        unset($img);

        return $data;
    }

    private function copyAvatar(\GdImage $tile, NfcProfile $profile, int $offset, int $size): bool
    {
        $path = (string) $profile->avatar_path;
        if ($path === '') {
            return false;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return false;
            }

            $source = imagecreatefromstring($disk->get($path));
            if (! $source instanceof \GdImage) {
                return false;
            }

            $edge = min(imagesx($source), imagesy($source));
            $srcX = intdiv(imagesx($source) - $edge, 2);
            $srcY = intdiv(imagesy($source) - $edge, 2);
            imagecopyresampled(
                $tile,
                $source,
                $offset,
                $offset,
                $srcX,
                $srcY,
                $size,
                $size,
                $edge,
                $edge
            );
            unset($source);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function drawInitials(\GdImage $tile, NfcProfile $profile, int $offset, int $size): void
    {
        [$red, $green, $blue] = sscanf($profile->brandColor(), '#%02x%02x%02x');
        $brand = imagecolorallocate($tile, $red, $green, $blue);
        imagefilledrectangle($tile, $offset, $offset, $offset + $size - 1, $offset + $size - 1, $brand);

        $initials = $profile->initials();
        $initials = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $initials) ?: '';
        $initials = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $initials), 0, 2) ?: '?';
        $white = imagecolorallocate($tile, 255, 255, 255);
        $font = 5;
        $sourceWidth = imagefontwidth($font) * strlen($initials);
        $sourceHeight = imagefontheight($font);
        $mask = imagecreatetruecolor($sourceWidth, $sourceHeight);
        $black = imagecolorallocate($mask, 0, 0, 0);
        $maskWhite = imagecolorallocate($mask, 255, 255, 255);
        imagefilledrectangle($mask, 0, 0, $sourceWidth - 1, $sourceHeight - 1, $black);
        imagestring($mask, $font, 0, 0, $initials, $maskWhite);

        $scale = max(1, (int) floor(min(
            ($size * .62) / $sourceWidth,
            ($size * .38) / $sourceHeight
        )));
        $targetWidth = $sourceWidth * $scale;
        $targetHeight = $sourceHeight * $scale;
        $startX = intdiv(imagesx($tile) - $targetWidth, 2);
        $startY = intdiv(imagesy($tile) - $targetHeight, 2);

        for ($y = 0; $y < $sourceHeight; $y++) {
            for ($x = 0; $x < $sourceWidth; $x++) {
                if ((imagecolorat($mask, $x, $y) & 0xFFFFFF) !== 0xFFFFFF) {
                    continue;
                }

                $left = $startX + ($x * $scale);
                $top = $startY + ($y * $scale);
                imagefilledrectangle($tile, $left, $top, $left + $scale - 1, $top + $scale - 1, $white);
            }
        }
        unset($mask);
    }

    private function encodePng(\GdImage $image): string
    {
        ob_start();
        try {
            imagepng($image, null, 9);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}

<?php

namespace App\Services;

use App\Models\Card;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

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

    public function pngForCard(Card $card): string
    {
        return $this->png($card->qrUrl());
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
    public function png(string $payload): string
    {
        $matrix  = Encoder::encode($payload, $this->eccLevel(), Encoder::DEFAULT_BYTE_MODE_ECODING)->getMatrix();
        $modules = $matrix->getWidth();
        $total   = $modules + (self::QUIET_ZONE * 2);

        // Integer scale keeps every module pixel-crisp; no resampling blur.
        $scale = max(1, (int) floor(self::PNG_SIZE / $total));
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

        ob_start();
        imagepng($img, null, 9);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return $data;
    }
}

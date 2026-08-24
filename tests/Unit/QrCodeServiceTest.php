<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Models\NfcProfile;
use App\Services\QrCodeService;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use ErrorException;
use InvalidArgumentException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QrCodeServiceTest extends TestCase
{
    public function test_png_accepts_a_smaller_maximum_without_resampling_modules(): void
    {
        $png  = app(QrCodeService::class)->png('https://nfc.viteseo.ph/q/ABCDEFGH', 410);
        $info = getimagesizefromstring($png);

        $this->assertNotFalse($info);
        $this->assertLessThanOrEqual(410, $info[0]);
        $this->assertSame($info[0], $info[1]);
        $this->assertGreaterThanOrEqual(360, $info[0]);
    }

    public function test_default_png_remains_print_resolution(): void
    {
        $png  = app(QrCodeService::class)->png('https://nfc.viteseo.ph/q/ABCDEFGH');
        $info = getimagesizefromstring($png);

        $this->assertNotFalse($info);
        $this->assertGreaterThanOrEqual(1000, $info[0]);
        $this->assertSame($info[0], $info[1]);
    }

    public function test_png_rejects_a_non_positive_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(QrCodeService::class)->png('https://nfc.viteseo.ph/q/ABCDEFGH', 0);
    }

    public function test_png_rejects_a_maximum_too_small_for_one_pixel_per_module(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(QrCodeService::class)->png('https://nfc.viteseo.ph/q/ABCDEFGH', 10);
    }

    public function test_png_rendering_does_not_call_deprecated_gd_cleanup(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        }, E_DEPRECATED);

        try {
            $this->assertNotSame('', app(QrCodeService::class)->png('https://nfc.viteseo.ph/q/ABCDEFGH', 410));
        } finally {
            restore_error_handler();
        }
    }

    public function test_profile_png_uses_the_profile_url_and_embeds_the_avatar_at_level_h(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/avatar.png', $this->solidPng(64, 220, 30, 40));

        $card = new Card();
        $card->forceFill(['public_id' => 'PROFILE1']);

        $profile = new NfcProfile(['display_name' => 'Uriel Papa']);
        $profile->forceFill(['avatar_path' => 'profile-avatars/avatar.png']);

        $service = app(QrCodeService::class);
        $profilePng = $service->pngForProfile($card, $profile);
        $cardPng    = $service->pngForCard($card);
        $info       = getimagesizefromstring($profilePng);

        $this->assertNotFalse($info);
        $this->assertGreaterThanOrEqual(1000, $info[0]);
        $this->assertSame($info[0], $info[1]);
        $this->assertNotSame($cardPng, $profilePng);

        $image = imagecreatefromstring($profilePng);
        $this->assertInstanceOf(\GdImage::class, $image);
        $centre = imagecolorsforindex(
            $image,
            imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2))
        );
        $this->assertGreaterThan(190, $centre['red']);
        $this->assertLessThan(60, $centre['green']);
        $this->assertLessThan(70, $centre['blue']);

        $matrix = Encoder::encode(
            $card->nfcUrl(),
            ErrorCorrectionLevel::H(),
            Encoder::DEFAULT_BYTE_MODE_ECODING
        )->getMatrix();
        $total = $matrix->getWidth() + (QrCodeService::QUIET_ZONE * 2);
        $scale = (int) floor(QrCodeService::PNG_SIZE / $total);
        $this->assertSame($total * $scale, imagesx($image), 'profile QR must use the level-H /n/ matrix');

        $markSize = (int) floor(imagesx($image) * .22);
        $markStart = intdiv(imagesx($image) - $markSize, 2);
        $markEnd = $markStart + $markSize - 1;
        $mismatches = [];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $px = (($x + QrCodeService::QUIET_ZONE) * $scale) + intdiv($scale, 2);
                $py = (($y + QrCodeService::QUIET_ZONE) * $scale) + intdiv($scale, 2);
                if ($px >= $markStart && $px <= $markEnd && $py >= $markStart && $py <= $markEnd) {
                    continue;
                }

                $rgb = imagecolorsforindex($image, imagecolorat($image, $px, $py));
                $expected = $matrix->get($x, $y) === 1 ? 0 : 255;
                if ($rgb['red'] !== $expected) {
                    $mismatches[] = "{$x},{$y}";
                }
            }
        }
        $this->assertSame([], $mismatches, 'profile QR modules differ from the level-H /n/ matrix');

        [$redStart, $redEnd] = $this->redPixelBounds($image);
        $this->assertLessThanOrEqual($markSize, $redEnd - $redStart + 1);
        $this->assertEqualsWithDelta(imagesx($image) / 2, ($redStart + $redEnd) / 2, 1);
    }

    public function test_profile_png_without_an_avatar_uses_a_valid_initials_tile(): void
    {
        Storage::fake('public');

        $card = new Card();
        $card->forceFill(['public_id' => 'PROFILE2']);
        $profile = new NfcProfile(['display_name' => 'Uriel Papa']);

        $png = app(QrCodeService::class)->pngForProfile($card, $profile, 410);
        $info = getimagesizefromstring($png);

        $this->assertNotFalse($info);
        $this->assertLessThanOrEqual(410, $info[0]);
        $this->assertSame($info[0], $info[1]);
    }

    public function test_profile_png_renders_large_visible_initials_for_non_ascii_names(): void
    {
        Storage::fake('public');

        $card = new Card();
        $card->forceFill(['public_id' => 'PROFILE3']);
        $profile = new NfcProfile(['display_name' => 'Élodie 张']);

        $image = imagecreatefromstring(app(QrCodeService::class)->pngForProfile($card, $profile, 410));
        $this->assertInstanceOf(\GdImage::class, $image);

        $size = imagesx($image);
        $markSize = (int) floor($size * .22);
        $start = intdiv($size - $markSize, 2) + intdiv($markSize, 5);
        $end = $size - $start - 1;
        $whitePixels = 0;

        for ($y = $start; $y <= $end; $y++) {
            for ($x = $start; $x <= $end; $x++) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($rgb['red'] > 245 && $rgb['green'] > 245 && $rgb['blue'] > 245) {
                    $whitePixels++;
                }
            }
        }

        $this->assertGreaterThan(100, $whitePixels, 'initials must remain readable when the PNG is shown at modal size');
    }

    private function solidPng(int $size, int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor($size, $size);
        $colour = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $colour);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array{int, int} */
    private function redPixelBounds(\GdImage $image): array
    {
        $first = imagesx($image);
        $last = -1;

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($rgb['red'] > 190 && $rgb['green'] < 80 && $rgb['blue'] < 90) {
                    $first = min($first, $x);
                    $last = max($last, $x);
                }
            }
        }

        $this->assertGreaterThanOrEqual(0, $last, 'embedded avatar colour was not found');

        return [$first, $last];
    }
}

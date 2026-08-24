<?php

namespace Tests\Unit;

use App\Services\QrCodeService;
use ErrorException;
use InvalidArgumentException;
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
}

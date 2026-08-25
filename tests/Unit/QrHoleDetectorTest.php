<?php

namespace Tests\Unit;

use App\Services\QrHoleDetector;
use GdImage;
use Tests\TestCase;

class QrHoleDetectorTest extends TestCase
{
    public function test_a_250_square_hole_returns_that_inscribed_box(): void
    {
        $image = $this->canvas();
        $this->hole($image, 80, 90, 250, 250);

        $this->assertSame(['x' => 80, 'y' => 90, 'size' => 250], (new QrHoleDetector())->inscribedSquare($image));
        unset($image);
    }

    public function test_a_rectangular_hole_gets_a_centered_inscribed_square(): void
    {
        $image = $this->canvas();
        $this->hole($image, 40, 50, 300, 400);

        $this->assertSame(['x' => 40, 'y' => 100, 'size' => 300], (new QrHoleDetector())->inscribedSquare($image));
        unset($image);
    }

    public function test_the_largest_hole_wins_when_two_are_present(): void
    {
        $image = $this->canvas();
        $this->hole($image, 10, 10, 210, 210);
        $this->hole($image, 300, 400, 250, 250);

        $this->assertSame(['x' => 300, 'y' => 400, 'size' => 250], (new QrHoleDetector())->inscribedSquare($image));
        unset($image);
    }

    public function test_a_thin_strip_or_missing_hole_is_rejected(): void
    {
        $empty = $this->canvas();
        $strip = $this->canvas();
        $this->hole($strip, 20, 20, 40, 400);

        $detector = new QrHoleDetector();
        $this->assertNull($detector->inscribedSquare($empty));
        $this->assertNull($detector->inscribedSquare($strip));
        unset($empty, $strip);
    }

    public function test_a_near_fully_transparent_hole_is_detected(): void
    {
        $image = $this->canvas();
        $clear = imagecolorallocatealpha($image, 0, 0, 0, QrHoleDetector::TRANSPARENT_ALPHA);
        imagefilledrectangle($image, 80, 90, 329, 339, $clear);

        $this->assertSame(['x' => 80, 'y' => 90, 'size' => 250], (new QrHoleDetector())->inscribedSquare($image));
        unset($image);
    }

    public function test_largest_transparent_square_reports_sub_minimum_holes_for_diagnostics(): void
    {
        $image = $this->canvas();
        $this->hole($image, 200, 300, 75, 75);

        $detector = new QrHoleDetector();
        $this->assertNull($detector->inscribedSquare($image));
        $this->assertSame(['x' => 200, 'y' => 300, 'size' => 75], $detector->largestTransparentSquare($image));
        unset($image);
    }

    public function test_a_large_thin_band_does_not_mask_a_smaller_real_hole(): void
    {
        // The band's rectangle has far more area than the hole, but its
        // inscribed square is under the minimum — the hole must still win.
        $image = $this->canvas();
        $this->hole($image, 0, 0, 638, 110);
        $this->hole($image, 200, 500, 130, 130);

        $this->assertSame(
            ['x' => 200, 'y' => 500, 'size' => 130],
            (new QrHoleDetector())->inscribedSquare($image)
        );
        unset($image);
    }

    public function test_the_minimum_hole_size_is_a_hard_boundary(): void
    {
        $atMinimum = $this->canvas();
        $this->hole($atMinimum, 30, 40, QrHoleDetector::MIN_SIZE, QrHoleDetector::MIN_SIZE);

        $justUnder = $this->canvas();
        $this->hole($justUnder, 30, 40, QrHoleDetector::MIN_SIZE - 1, QrHoleDetector::MIN_SIZE - 1);

        $detector = new QrHoleDetector();
        $this->assertSame(
            ['x' => 30, 'y' => 40, 'size' => QrHoleDetector::MIN_SIZE],
            $detector->inscribedSquare($atMinimum)
        );
        $this->assertNull($detector->inscribedSquare($justUnder));
        unset($atMinimum, $justUnder);
    }

    private function canvas(): GdImage
    {
        $image = imagecreatetruecolor(638, 1011);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $opaque = imagecolorallocatealpha($image, 20, 20, 20, 0);
        imagefilledrectangle($image, 0, 0, 637, 1010, $opaque);

        return $image;
    }

    private function hole(GdImage $image, int $x, int $y, int $width, int $height): void
    {
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, $clear);
    }
}

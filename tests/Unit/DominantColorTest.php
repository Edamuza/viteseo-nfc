<?php

namespace Tests\Unit;

use App\Models\NfcProfile;
use App\Services\DominantColor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DominantColorTest extends TestCase
{
    /** @return array<string, array{array{int,int,int}}> */
    public static function strongHues(): array
    {
        return [
            'red' => [[220, 30, 40]],
            'green' => [[20, 190, 80]],
            'blue' => [[30, 80, 230]],
        ];
    }

    #[DataProvider('strongHues')]
    public function test_it_preserves_a_strong_dominant_hue_inside_the_dark_band(array $rgb): void
    {
        $result = app(DominantColor::class)->extract($this->solid(...$rgb));
        $derived = $this->rgb($result);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $result);
        $this->assertEqualsWithDelta($this->hue($rgb), $this->hue($derived), 8);
        $this->assertGreaterThanOrEqual(.18, $this->lightness($derived));
        $this->assertLessThanOrEqual(.24, $this->lightness($derived));
    }

    public function test_grayscale_and_fully_transparent_images_use_the_default(): void
    {
        $this->assertSame(
            NfcProfile::DEFAULT_BRAND_COLOR,
            app(DominantColor::class)->extract($this->solid(120, 120, 120))
        );

        $transparent = imagecreatetruecolor(64, 64);
        imagealphablending($transparent, false);
        imagesavealpha($transparent, true);
        $clear = imagecolorallocatealpha($transparent, 0, 0, 0, 127);
        imagefilledrectangle($transparent, 0, 0, 63, 63, $clear);

        $this->assertSame(
            NfcProfile::DEFAULT_BRAND_COLOR,
            app(DominantColor::class)->extract($transparent)
        );
    }

    public function test_the_majority_colour_wins_over_a_smaller_accent(): void
    {
        $image = imagecreatetruecolor(100, 100);
        $red = imagecolorallocate($image, 220, 30, 40);
        $blue = imagecolorallocate($image, 30, 80, 230);
        imagefilledrectangle($image, 0, 0, 99, 99, $red);
        imagefilledrectangle($image, 80, 0, 99, 99, $blue);

        $derived = $this->rgb(app(DominantColor::class)->extract($image));

        $this->assertEqualsWithDelta($this->hue([220, 30, 40]), $this->hue($derived), 8);
    }

    public function test_an_internal_gd_failure_returns_the_default_colour(): void
    {
        $service = new class extends DominantColor
        {
            protected function sample(\GdImage $source): \GdImage
            {
                throw new \RuntimeException('simulated GD allocation failure');
            }
        };

        $this->assertSame(
            NfcProfile::DEFAULT_BRAND_COLOR,
            $service->extract($this->solid(220, 30, 40))
        );
    }

    /** @return array<string, array{array{int,int,int}}> */
    public static function extremeColours(): array
    {
        return [
            'pale rose' => [[255, 180, 200]],
            'neon yellow' => [[245, 255, 0]],
        ];
    }

    #[DataProvider('extremeColours')]
    public function test_pale_and_neon_colours_keep_their_hue_but_not_their_lightness(array $rgb): void
    {
        $derived = $this->rgb(app(DominantColor::class)->extract($this->solid(...$rgb)));

        $this->assertEqualsWithDelta($this->hue($rgb), $this->hue($derived), 8);
        $this->assertGreaterThanOrEqual(.18, $this->lightness($derived));
        $this->assertLessThanOrEqual(.24, $this->lightness($derived));
    }

    private function solid(int $red, int $green, int $blue): \GdImage
    {
        $image = imagecreatetruecolor(64, 64);
        $colour = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 0, 0, 63, 63, $colour);

        return $image;
    }

    /** @return array{int,int,int} */
    private function rgb(string $hex): array
    {
        return sscanf($hex, '#%02x%02x%02x');
    }

    /** @param array{int,int,int} $rgb */
    private function lightness(array $rgb): float
    {
        return (max($rgb) + min($rgb)) / 510;
    }

    /** @param array{int,int,int} $rgb */
    private function hue(array $rgb): float
    {
        [$red, $green, $blue] = array_map(fn (int $value) => $value / 255, $rgb);
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $delta = $max - $min;

        if ($delta === 0.0) {
            return 0;
        }

        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta, 6),
            $green => (($blue - $red) / $delta) + 2,
            default => (($red - $green) / $delta) + 4,
        };

        return fmod(($hue * 60) + 360, 360);
    }
}

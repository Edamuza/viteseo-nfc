<?php

namespace App\Services;

use App\Models\NfcProfile;
use RuntimeException;
use Throwable;

class DominantColor
{
    public function extract(\GdImage $source): string
    {
        try {
            return $this->extractFromSample($this->sample($source));
        } catch (Throwable) {
            return NfcProfile::DEFAULT_BRAND_COLOR;
        }
    }

    protected function sample(\GdImage $source): \GdImage
    {
        $sample = imagecreatetruecolor(32, 32);
        if (! $sample instanceof \GdImage) {
            throw new RuntimeException('Could not allocate the colour sample.');
        }

        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        $clear = imagecolorallocatealpha($sample, 0, 0, 0, 127);
        imagefilledrectangle($sample, 0, 0, 31, 31, $clear);
        if (! imagecopyresampled($sample, $source, 0, 0, 0, 0, 32, 32, imagesx($source), imagesy($source))) {
            throw new RuntimeException('Could not resize the colour sample.');
        }

        return $sample;
    }

    private function extractFromSample(\GdImage $sample): string
    {
        $buckets = [];

        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $pixel = imagecolorsforindex($sample, imagecolorat($sample, $x, $y));
                $red = $pixel['red'];
                $green = $pixel['green'];
                $blue = $pixel['blue'];

                if ($pixel['alpha'] >= 120
                    || ($red > 245 && $green > 245 && $blue > 245)
                    || ($red < 15 && $green < 15 && $blue < 15)) {
                    continue;
                }

                [, $saturation] = $this->rgbToHsl($red, $green, $blue);
                if ($saturation < .12) {
                    continue;
                }

                $key = (($red >> 4) << 8) | (($green >> 4) << 4) | ($blue >> 4);
                $bucket = $buckets[$key] ?? ['count' => 0, 'red' => 0, 'green' => 0, 'blue' => 0];
                $bucket['count']++;
                $bucket['red'] += $red;
                $bucket['green'] += $green;
                $bucket['blue'] += $blue;
                $buckets[$key] = $bucket;
            }
        }
        unset($sample);

        if ($buckets === []) {
            return NfcProfile::DEFAULT_BRAND_COLOR;
        }

        uasort($buckets, fn (array $left, array $right) => $right['count'] <=> $left['count']);
        $winner = reset($buckets);

        if (! is_array($winner) || $winner['count'] < 8) {
            return NfcProfile::DEFAULT_BRAND_COLOR;
        }

        $red = (int) round($winner['red'] / $winner['count']);
        $green = (int) round($winner['green'] / $winner['count']);
        $blue = (int) round($winner['blue'] / $winner['count']);
        [$hue, $saturation] = $this->rgbToHsl($red, $green, $blue);
        [$red, $green, $blue] = $this->hslToRgb($hue, min(.78, max(.35, $saturation)), .21);

        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    /** @return array{float, float, float} */
    private function rgbToHsl(int $red, int $green, int $blue): array
    {
        $red /= 255;
        $green /= 255;
        $blue /= 255;
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $delta = $max - $min;
        $lightness = ($max + $min) / 2;

        if ($delta === 0.0) {
            return [0, 0, $lightness];
        }

        $saturation = $delta / (1 - abs((2 * $lightness) - 1));
        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta, 6),
            $green => (($blue - $red) / $delta) + 2,
            default => (($red - $green) / $delta) + 4,
        };

        return [fmod(($hue * 60) + 360, 360), $saturation, $lightness];
    }

    /** @return array{int, int, int} */
    private function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs((2 * $lightness) - 1)) * $saturation;
        $segment = $hue / 60;
        $x = $chroma * (1 - abs(fmod($segment, 2) - 1));

        [$red, $green, $blue] = match (true) {
            $segment < 1 => [$chroma, $x, 0],
            $segment < 2 => [$x, $chroma, 0],
            $segment < 3 => [0, $chroma, $x],
            $segment < 4 => [0, $x, $chroma],
            $segment < 5 => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        $match = $lightness - ($chroma / 2);

        return [
            (int) round(($red + $match) * 255),
            (int) round(($green + $match) * 255),
            (int) round(($blue + $match) * 255),
        ];
    }
}

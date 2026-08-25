<?php

namespace App\Services;

use GdImage;

class QrHoleDetector
{
    /**
     * Smallest QR hole a template may declare, in canvas pixels.
     *
     * The 638x1011 canvas prints at roughly credit-card size (~54mm wide), so
     * ~11.8px per printed millimetre. 120px is therefore about a 10mm QR — the
     * common minimum for reliable close-range scanning. Below that, print dots
     * and phone cameras start missing modules, and a fancy sticker that does
     * not scan is worthless. It also keeps stray transparent specks in the
     * artwork from being mistaken for the hole.
     */
    public const MIN_SIZE = 120;

    /**
     * @return array{x: int, y: int, size: int}|null
     */
    public function inscribedSquare(GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = array_fill(0, $width, 0);
        $best = null;
        $bestArea = 0;

        for ($row = 0; $row < $height; $row++) {
            for ($column = 0; $column < $width; $column++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $column, $row));
                $histogram[$column] = ($color['alpha'] ?? 0) === 127 ? $histogram[$column] + 1 : 0;
            }

            $this->considerHistogram($histogram, $row, $bestArea, $best);
        }

        return $best;
    }

    /**
     * @param  array<int, int>  $heights
     * @param  array{x: int, y: int, size: int}|null  $best
     */
    private function considerHistogram(array $heights, int $row, int &$bestArea, ?array &$best): void
    {
        $heights[] = 0;
        $stack = [];

        for ($index = 0; $index < count($heights); $index++) {
            while ($stack !== [] && $heights[$stack[array_key_last($stack)]] > $heights[$index]) {
                $bar = $heights[array_pop($stack)];
                $left = $stack === [] ? 0 : $stack[array_key_last($stack)] + 1;
                $width = $index - $left;
                $size = min($width, $bar);

                if ($size < self::MIN_SIZE) {
                    continue;
                }

                $area = $width * $bar;
                if ($area < $bestArea) {
                    continue;
                }

                $bestArea = $area;
                $best = [
                    'x' => $left + intdiv($width - $size, 2),
                    'y' => ($row - $bar + 1) + intdiv($bar - $size, 2),
                    'size' => $size,
                ];
            }

            $stack[] = $index;
        }
    }
}

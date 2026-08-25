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
     * A pixel counts as "hole" from this GD alpha upward (127 = fully clear).
     *
     * Real design-tool exports routinely leave the hole 97-99% transparent
     * instead of exactly 100% — soft erasers, layer opacity, export
     * quantization — and resampling to the print canvas can shave the last
     * bit too. Requiring exactly 127 made such holes invisible to the
     * detector even though they look fully transparent to the eye. 124/127
     * (~97.6% transparent) accepts those while still rejecting anything a
     * viewer would perceive as tinted.
     */
    public const TRANSPARENT_ALPHA = 124;

    /**
     * The largest transparent square that meets MIN_SIZE, or null.
     *
     * @return array{x: int, y: int, size: int}|null
     */
    public function inscribedSquare(GdImage $image): ?array
    {
        return $this->scan($image)['qualifying'];
    }

    /**
     * The largest transparent square of ANY size, or null when the image has
     * no transparent pixels at all. Diagnostic only — lets an upload error say
     * "we found a 75px hole, the minimum is 120" instead of "no hole", which
     * reads as nonsense to a designer whose source file has a big hole that
     * shrank when the artwork was scaled to the print canvas.
     *
     * @return array{x: int, y: int, size: int}|null
     */
    public function largestTransparentSquare(GdImage $image): ?array
    {
        return $this->scan($image)['largest'];
    }

    /**
     * One pass, two answers: the best square that qualifies (area-ranked among
     * rectangles whose inscribed square meets MIN_SIZE — a huge thin strip
     * must never displace a smaller true hole) and the largest square overall
     * (size-ranked, for diagnostics).
     *
     * @return array{qualifying: array{x: int, y: int, size: int}|null, largest: array{x: int, y: int, size: int}|null}
     */
    public function scan(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = array_fill(0, $width, 0);
        $qualifying = null;
        $qualifyingArea = 0;
        $largest = null;

        for ($row = 0; $row < $height; $row++) {
            for ($column = 0; $column < $width; $column++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $column, $row));
                $histogram[$column] = ($color['alpha'] ?? 0) >= self::TRANSPARENT_ALPHA ? $histogram[$column] + 1 : 0;
            }

            $this->considerHistogram($histogram, $row, $qualifyingArea, $qualifying, $largest);
        }

        return ['qualifying' => $qualifying, 'largest' => $largest];
    }

    /**
     * @param  array<int, int>  $heights
     * @param  array{x: int, y: int, size: int}|null  $qualifying
     * @param  array{x: int, y: int, size: int}|null  $largest
     */
    private function considerHistogram(array $heights, int $row, int &$qualifyingArea, ?array &$qualifying, ?array &$largest): void
    {
        $heights[] = 0;
        $stack = [];

        for ($index = 0; $index < count($heights); $index++) {
            while ($stack !== [] && $heights[$stack[array_key_last($stack)]] > $heights[$index]) {
                $bar = $heights[array_pop($stack)];
                $left = $stack === [] ? 0 : $stack[array_key_last($stack)] + 1;
                $width = $index - $left;
                $size = min($width, $bar);

                if ($size < 1) {
                    continue;
                }

                $square = [
                    'x' => $left + intdiv($width - $size, 2),
                    'y' => ($row - $bar + 1) + intdiv($bar - $size, 2),
                    'size' => $size,
                ];

                if ($largest === null || $size > $largest['size']) {
                    $largest = $square;
                }

                if ($size < self::MIN_SIZE) {
                    continue;
                }

                $area = $width * $bar;
                if ($area < $qualifyingArea) {
                    continue;
                }

                $qualifyingArea = $area;
                $qualifying = $square;
            }

            $stack[] = $index;
        }
    }
}

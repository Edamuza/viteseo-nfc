<?php

namespace App\Services;

use App\Models\Card;
use App\Models\StickerTemplate;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class CardReviewStickerService
{
    public const WIDTH = 638;

    public const HEIGHT = 1011;

    private const CORNER_RADIUS = 14;

    public function __construct(private readonly QrCodeService $qr) {}

    public function frontPng(?StickerTemplate $template = null): string
    {
        $image = $this->artwork($this->resolve($template)->front_path);

        try {
            return $this->encode($image);
        } finally {
            unset($image);
        }
    }

    public function backPng(Card $card, ?StickerTemplate $template = null): string
    {
        $template = $this->resolve($template);
        $back = $this->artwork($template->back_path);
        $qr = imagecreatefromstring($this->qr->pngForCard($card, max(1, $template->qr_size)));

        if (! $qr instanceof GdImage) {
            unset($back);
            throw new RuntimeException('Could not decode the generated card QR.');
        }

        try {
            // The QR fills the template's QR box exactly, edge to edge. It used
            // to be rendered 16px smaller and centred over a white fill, and
            // integer module scaling shrank it further — a ~10px white ring on
            // every side that doubled the white margin the artwork already
            // draws around the box. The integer-scaled render is stretched the
            // last few pixels with nearest-neighbour, which keeps modules pure
            // black/white; any quiet zone is the template design's job.
            if (imagesx($qr) !== $template->qr_size || imagesy($qr) !== $template->qr_size) {
                $exact = imagescale($qr, $template->qr_size, $template->qr_size, IMG_NEAREST_NEIGHBOUR);
                if (! $exact instanceof GdImage) {
                    throw new RuntimeException('Could not scale the card QR to the template QR box.');
                }
                unset($qr);
                $qr = $exact;
            }

            imagealphablending($back, true);
            imagecopy($back, $qr, $template->qr_x, $template->qr_y, 0, 0, imagesx($qr), imagesy($qr));

            return $this->encode($back);
        } finally {
            unset($qr, $back);
        }
    }

    public function downloadBasename(Card $card): string
    {
        if (preg_match('/^CARD-0*(\d+)$/i', $card->card_number, $matches) !== 1) {
            throw new RuntimeException('Card number cannot be used as a sticker filename.');
        }

        return 'card-'.(int) $matches[1];
    }

    public function zipFor(Card $card, ?StickerTemplate $template = null): string
    {
        $template = $this->resolve($template);

        return $this->archive('sticker-template-', function (ZipArchive $zip) use ($card, $template): void {
            $base = $this->downloadBasename($card);
            $this->addEntry($zip, $base.'-front.png', $this->frontPng($template));
            $this->addEntry($zip, $base.'-back.png', $this->backPng($card, $template));
        });
    }

    public function zipForCards(iterable $cards, ?StickerTemplate $template = null): string
    {
        $template = $this->resolve($template);
        $front = $this->frontPng($template);

        return $this->archive('sticker-templates-', function (ZipArchive $zip) use ($cards, $front, $template): void {
            foreach ($cards as $card) {
                if (! $card instanceof Card) {
                    throw new RuntimeException('Sticker exports require Card models.');
                }

                $base = $this->downloadBasename($card);
                $this->addEntry($zip, $base.'-front.png', $front);
                $this->addEntry($zip, $base.'-back.png', $this->backPng($card, $template));
            }
        });
    }

    private function archive(string $prefix, callable $populate): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the sticker archive directory.');
        }

        $path = $directory.'/'.$prefix.bin2hex(random_bytes(8)).'.zip';
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the sticker archive.');
        }

        $open = true;

        try {
            $populate($zip);

            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the sticker archive.');
            }
            $open = false;

            $bytes = file_get_contents($path);
            if (! is_string($bytes)) {
                throw new RuntimeException('Could not read the sticker archive.');
            }

            return $bytes;
        } finally {
            if ($open) {
                $zip->close();
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function addEntry(ZipArchive $zip, string $name, string $bytes): void
    {
        if (! $zip->addFromString($name, $bytes)) {
            throw new RuntimeException('Could not add the sticker artwork to the archive.');
        }
    }

    private function resolve(?StickerTemplate $template): StickerTemplate
    {
        $template ??= StickerTemplate::active();

        if ($template === null) {
            throw new RuntimeException('No active sticker template is available.');
        }

        return $template;
    }

    private function artwork(string $path): GdImage
    {
        $bytes = Storage::disk('public')->get($path);
        $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if (! $source instanceof GdImage) {
            throw new RuntimeException('Could not decode sticker artwork: '.$path);
        }

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (! $canvas instanceof GdImage) {
            unset($source);
            throw new RuntimeException('Could not allocate the sticker canvas.');
        }

        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $transparent);
            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                0,
                0,
                self::WIDTH,
                self::HEIGHT,
                imagesx($source),
                imagesy($source)
            );
            $this->applyRoundedMask($canvas, $transparent);
        } finally {
            unset($source);
        }

        return $canvas;
    }

    private function applyRoundedMask(GdImage $image, int $transparent): void
    {
        $radiusSquared = self::CORNER_RADIUS ** 2;

        for ($y = 0; $y < self::CORNER_RADIUS; $y++) {
            for ($x = 0; $x < self::CORNER_RADIUS; $x++) {
                $dx = self::CORNER_RADIUS - $x - .5;
                $dy = self::CORNER_RADIUS - $y - .5;

                if (($dx * $dx) + ($dy * $dy) <= $radiusSquared) {
                    continue;
                }

                imagesetpixel($image, $x, $y, $transparent);
                imagesetpixel($image, self::WIDTH - 1 - $x, $y, $transparent);
                imagesetpixel($image, $x, self::HEIGHT - 1 - $y, $transparent);
                imagesetpixel($image, self::WIDTH - 1 - $x, self::HEIGHT - 1 - $y, $transparent);
            }
        }
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        try {
            if (! imagepng($image, null, 9)) {
                throw new RuntimeException('Could not encode sticker artwork.');
            }

            $bytes = ob_get_contents();
            if (! is_string($bytes)) {
                throw new RuntimeException('Could not read encoded sticker artwork.');
            }

            return $bytes;
        } finally {
            ob_end_clean();
        }
    }
}

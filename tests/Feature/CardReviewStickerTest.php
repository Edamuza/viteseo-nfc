<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\StickerTemplate;
use App\Models\User;
use App\Services\CardReviewStickerService;
use App\Services\QrCodeService;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class CardReviewStickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        StickerTemplate::restoreBundledFiles();
    }

    public function test_the_sticker_composer_exists(): void
    {
        $this->assertTrue(class_exists(CardReviewStickerService::class));
    }

    public function test_it_produces_standard_card_faces_and_the_requested_basename(): void
    {
        $card    = $this->card();
        $service = app(CardReviewStickerService::class);

        $this->assertSame([638, 1011], $this->dimensions($service->frontPng()));
        $this->assertSame([638, 1011], $this->dimensions($service->backPng($card)));
        $this->assertSame('card-50', $service->downloadBasename($card));
    }

    public function test_only_the_back_changes_between_cards(): void
    {
        $first   = $this->card('CARD-0050');
        $second  = $this->card('CARD-0051');
        $service = app(CardReviewStickerService::class);

        $this->assertSame($service->frontPng(), $service->frontPng());
        $this->assertNotSame($service->backPng($first), $service->backPng($second));
    }

    public function test_changing_only_the_public_id_changes_the_printed_qr(): void
    {
        $card    = $this->card();
        $service = app(CardReviewStickerService::class);
        $before  = $service->backPng($card);

        $card->forceFill(['public_id' => 'DIFFQR01']);

        $this->assertNotSame($before, $service->backPng($card));
    }

    public function test_changing_the_destination_never_changes_the_printed_qr(): void
    {
        $card    = $this->card();
        $service = app(CardReviewStickerService::class);
        $before  = $service->backPng($card);

        $card->forceFill([
            'qr_destination'   => 'https://example.com/a-new-place',
            'qr_configured_at' => now(),
        ])->save();

        $this->assertSame($before, $service->backPng($card->fresh()));
    }

    public function test_the_rounded_card_corners_are_transparent_not_black(): void
    {
        $front = imagecreatefromstring(app(CardReviewStickerService::class)->frontPng());
        $this->assertInstanceOf(GdImage::class, $front);

        $this->assertSame(127, $this->rgb($front, 0, 0)['alpha']);
        $this->assertSame(0, $this->rgb($front, 20, 20)['alpha']);
    }

    public function test_the_generated_qr_fills_the_box_without_covering_the_scanner_brackets(): void
    {
        $card    = $this->card();
        $service = app(CardReviewStickerService::class);
        $back    = imagecreatefromstring($service->backPng($card));
        $qr      = $this->qrScaledToBox($card, 426);

        $this->assertInstanceOf(GdImage::class, $back);
        $this->assertInstanceOf(GdImage::class, $qr);

        // Edge to edge: the QR sits at the box origin with no white ring — the
        // 16px inset plus the integer-scale remainder used to leave ~10px of
        // white on every side inside the box.
        $this->assertSame([426, 426], [imagesx($qr), imagesy($qr)]);
        $this->assertTrue($this->samePixels($back, $qr, 106, 77), 'the back does not contain the selected card QR');
        $this->assertTrue($this->isPureBlackAndWhite($qr), 'QR modules were antialiased or recoloured');

        // The flush QR's top-left finder module lands exactly on the box corner.
        $corner = $this->rgb($back, 106, 77);
        $this->assertLessThan(60, $corner['red'], 'the QR does not reach the top-left corner of its box');

        $bracket = $this->rgb($back, 150, 56);
        $this->assertLessThan(220, $bracket['red'], 'the top scanner bracket was painted over');
        $this->assertGreaterThan(100, $bracket['red'], 'the top scanner bracket is no longer grey');
        $bracketRgb = array_intersect_key($bracket, array_flip(['red', 'green', 'blue']));
        $this->assertLessThanOrEqual(3, max($bracketRgb) - min($bracketRgb), 'the scanner bracket lost its neutral grey');

        unset($qr, $back);
    }

    /** The exact image backPng() composes: the flush QR stretched to the box. */
    private function qrScaledToBox(Card $card, int $boxSize): GdImage
    {
        $qr = imagecreatefromstring(app(QrCodeService::class)->pngForCard($card, $boxSize));
        $this->assertInstanceOf(GdImage::class, $qr);

        if (imagesx($qr) !== $boxSize) {
            $qr = imagescale($qr, $boxSize, $boxSize, IMG_NEAREST_NEIGHBOUR);
            $this->assertInstanceOf(GdImage::class, $qr);
        }

        return $qr;
    }

    public function test_the_zip_contains_exactly_the_two_named_pngs(): void
    {
        $card    = $this->card();
        $service = app(CardReviewStickerService::class);
        $entries = $this->zipEntries($service->zipFor($card));

        $this->assertSame(['card-50-back.png', 'card-50-front.png'], array_keys($entries));

        foreach ($entries as $png) {
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
            $this->assertSame([638, 1011], $this->dimensions($png));
        }
    }

    public function test_zip_generation_leaves_no_temporary_archive(): void
    {
        $card    = $this->card();
        $service = app(CardReviewStickerService::class);
        $before  = glob(storage_path('app/tmp/sticker-template-*.zip')) ?: [];

        $this->assertNotSame('', $service->zipFor($card));

        $after = glob(storage_path('app/tmp/sticker-template-*.zip')) ?: [];
        $this->assertSame($before, $after);
    }

    public function test_failed_zip_generation_also_removes_its_temporary_archive(): void
    {
        $card = new Card();
        $card->forceFill([
            'card_number' => '../invalid',
            'public_id'   => 'FAILZIP1',
        ]);

        $before = glob(storage_path('app/tmp/sticker-template-*.zip')) ?: [];

        try {
            app(CardReviewStickerService::class)->zipFor($card);
            $this->fail('Invalid card numbers must stop ZIP generation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Card number cannot be used as a sticker filename.', $exception->getMessage());
        }

        $after = glob(storage_path('app/tmp/sticker-template-*.zip')) ?: [];
        $this->assertSame($before, $after);
    }

    public function test_bulk_zip_contains_one_valid_front_and_back_pair_for_every_card(): void
    {
        $first = $this->card('CARD-0050');
        $first->forceFill([
            'qr_destination'   => 'https://example.com/review',
            'qr_configured_at' => now(),
        ])->save();
        $second = $this->card('CARD-0051');

        $entries = $this->zipEntries(
            app(CardReviewStickerService::class)->zipForCards([$first, $second])
        );

        $this->assertSame([
            'card-50-back.png',
            'card-50-front.png',
            'card-51-back.png',
            'card-51-front.png',
        ], array_keys($entries));
        $this->assertSame($entries['card-50-front.png'], $entries['card-51-front.png']);
        $this->assertNotSame($entries['card-50-back.png'], $entries['card-51-back.png']);

        foreach ($entries as $png) {
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
            $this->assertSame([638, 1011], $this->dimensions($png));
        }
    }

    public function test_bulk_zip_generation_leaves_no_temporary_archive(): void
    {
        $before = glob(storage_path('app/tmp/sticker-templates-*.zip')) ?: [];

        $this->assertNotSame(
            '',
            app(CardReviewStickerService::class)->zipForCards([
                $this->card('CARD-0050'),
                $this->card('CARD-0051'),
            ])
        );

        $after = glob(storage_path('app/tmp/sticker-templates-*.zip')) ?: [];
        $this->assertSame($before, $after);
    }

    public function test_failed_bulk_zip_generation_also_removes_its_temporary_archive(): void
    {
        $invalid = new Card();
        $invalid->forceFill([
            'card_number' => '../invalid',
            'public_id'   => 'FAILALL1',
        ]);

        $before = glob(storage_path('app/tmp/sticker-templates-*.zip')) ?: [];

        try {
            app(CardReviewStickerService::class)->zipForCards([
                $this->card('CARD-0050'),
                $invalid,
            ]);
            $this->fail('Invalid card numbers must stop bulk ZIP generation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Card number cannot be used as a sticker filename.', $exception->getMessage());
        }

        $after = glob(storage_path('app/tmp/sticker-templates-*.zip')) ?: [];
        $this->assertSame($before, $after);
    }

    public function test_the_bulk_template_export_requires_admin_authentication_and_post(): void
    {
        $this->post('/admin/export/sticker-templates')->assertRedirect('/admin/login');
        $this->actingAs($this->admin())
            ->get('/admin/export/sticker-templates')
            ->assertStatus(405);
    }

    public function test_the_dashboard_offers_the_bulk_template_export(): void
    {
        $body = $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'action="'.url('/admin/export/sticker-templates').'"',
            $body
        );
        $this->assertStringContainsString('Download all templates (PNG)', $body);
    }

    public function test_the_bulk_template_export_returns_not_found_when_there_are_no_cards(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin')
            ->post('/admin/export/sticker-templates', [
                'from' => 1,
                'to' => 10,
                'template_id' => \App\Models\StickerTemplate::active()->id,
            ])
            ->assertRedirect('/admin')
            ->assertSessionHasErrors('from');
    }

    public function test_the_bulk_template_export_downloads_every_card_pair_as_a_private_zip(): void
    {
        $first = $this->card('CARD-0050');
        $first->forceFill([
            'qr_destination'   => 'https://example.com/review',
            'qr_configured_at' => now(),
        ])->save();
        $this->card('CARD-0051');

        Carbon::setTestNow(Carbon::parse('2026-08-24 15:52:03'));
        try {
            $response = $this->actingAs($this->admin())
                ->post('/admin/export/sticker-templates', [
                    'from' => 50,
                    'to' => 51,
                    'template_id' => \App\Models\StickerTemplate::active()->id,
                ])
                ->assertOk()
                ->assertHeader('Content-Type', 'application/zip');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString(
            'attachment; filename="nfc-card-templates-20260824-155203.zip"',
            (string) $response->headers->get('Content-Disposition')
        );

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        $entries = $this->zipEntries($response->getContent());
        $this->assertSame([
            'card-50-back.png',
            'card-50-front.png',
            'card-51-back.png',
            'card-51-front.png',
        ], array_keys($entries));
    }

    public function test_the_sticker_download_requires_admin_authentication(): void
    {
        $card = $this->card();

        $this->get('/admin/cards/'.$card->public_id.'/sticker-template.zip')
            ->assertRedirect('/admin/login');
    }

    public function test_the_admin_qr_panel_offers_the_sticker_download_and_warns_before_setup(): void
    {
        $card = $this->card();

        $body = $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('admin.cards.sticker.download', $card->public_id).'"',
            $body
        );
        $this->assertStringContainsString('Download sticker template', $body);
        $this->assertStringContainsString(
            'The sticker can still be downloaded; scans open the setup page until a destination is set.',
            $body
        );
    }

    public function test_the_setup_warning_disappears_after_the_qr_is_configured(): void
    {
        $card = $this->card();
        $card->forceFill([
            'qr_destination'   => 'https://example.com/review',
            'qr_configured_at' => now(),
        ])->save();

        $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertDontSee('scans open the setup page until a destination is set');
    }

    public function test_the_admin_download_returns_the_named_private_zip(): void
    {
        $card = $this->card();

        $response = $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id.'/sticker-template.zip')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertStringContainsString(
            'attachment; filename="card-50-template.zip"',
            (string) $response->headers->get('Content-Disposition')
        );

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        $entries = $this->zipEntries($response->getContent());
        $this->assertSame(['card-50-back.png', 'card-50-front.png'], array_keys($entries));
    }

    private function card(string $number = 'CARD-0050'): Card
    {
        return Card::factory()->create(['card_number' => $number]);
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery-1'),
        ]);
    }

    /** @return array{int, int} */
    private function dimensions(string $png): array
    {
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);

        return [$info[0], $info[1]];
    }

    /** @return array{red:int, green:int, blue:int, alpha:int} */
    private function rgb(GdImage $image, int $x, int $y): array
    {
        return imagecolorsforindex($image, imagecolorat($image, $x, $y));
    }

    private function samePixels(GdImage $back, GdImage $qr, int $offsetX, int $offsetY): bool
    {
        for ($y = 0; $y < imagesy($qr); $y++) {
            for ($x = 0; $x < imagesx($qr); $x++) {
                if (imagecolorat($back, $offsetX + $x, $offsetY + $y) !== imagecolorat($qr, $x, $y)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isPureBlackAndWhite(GdImage $image): bool
    {
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $rgb = $this->rgb($image, $x, $y);
                if ($rgb['red'] !== $rgb['green'] || $rgb['green'] !== $rgb['blue']) {
                    return false;
                }
                if ($rgb['red'] !== 0 && $rgb['red'] !== 255) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function zipEntries(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sticker-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $this->assertIsString($name);
                $contents = $zip->getFromIndex($i);
                $this->assertIsString($contents);
                $entries[$name] = $contents;
            }
            ksort($entries);

            return $entries;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StickerTemplateController;
use App\Models\Card;
use App\Models\StickerTemplate;
use App\Models\User;
use App\Services\CardReviewStickerService;
use App\Services\QrCodeService;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class StickerTemplateLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        StickerTemplate::restoreBundledFiles();
    }

    public function test_the_bundled_artwork_is_seeded_as_the_active_default(): void
    {
        $template = StickerTemplate::query()->sole();

        $this->assertSame('Default', $template->name);
        $this->assertTrue($template->is_active);
        $this->assertSame([106, 77, 426], [$template->qr_x, $template->qr_y, $template->qr_size]);
        Storage::disk('public')->assertExists($template->front_path);
        Storage::disk('public')->assertExists($template->back_path);
        $this->assertSame($template->id, StickerTemplate::active()?->id);
    }

    public function test_request_data_cannot_fill_paths_or_the_detected_box(): void
    {
        $template = StickerTemplate::query()->sole();
        $template->fill([
            'name' => 'Hacked',
            'front_path' => 'evil.png',
            'back_path' => 'evil.png',
            'qr_x' => 1,
            'qr_y' => 2,
            'qr_size' => 3,
            'is_active' => false,
        ]);

        $this->assertSame('Hacked', $template->name);
        $this->assertNotSame('evil.png', $template->front_path);
        $this->assertSame(106, $template->qr_x);
        $this->assertTrue($template->is_active);
    }

    public function test_admin_can_create_a_template_with_the_hole_in_either_file(): void
    {
        // Hole in the second file.
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Green batch',
            'files' => [$this->png(false), $this->png(true, 80, 90, 250, 250)],
        ])->assertRedirect('/admin/sticker-templates')->assertSessionHasNoErrors();

        $created = StickerTemplate::query()->where('name', 'Green batch')->sole();
        $this->assertFalse($created->is_active);
        $this->assertSame([80, 90, 250], [$created->qr_x, $created->qr_y, $created->qr_size]);
        Storage::disk('public')->assertExists($created->front_path);
        Storage::disk('public')->assertExists($created->back_path);

        // Hole in the FIRST file: same result — the holed file is the QR side
        // regardless of selection order.
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Hole first',
            'files' => [$this->png(true, 80, 90, 250, 250), $this->png(false)],
        ])->assertRedirect('/admin/sticker-templates')->assertSessionHasNoErrors();

        $swapped = StickerTemplate::query()->where('name', 'Hole first')->sole();
        $this->assertSame([80, 90, 250], [$swapped->qr_x, $swapped->qr_y, $swapped->qr_size]);
    }

    public function test_uploads_without_exactly_one_usable_hole_are_rejected_and_insert_nothing(): void
    {
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'No hole',
            'files' => [$this->png(false), $this->png(false)],
        ])->assertSessionHasErrors('files');

        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Thin strip',
            'files' => [$this->png(false), $this->png(true, 20, 20, 40, 400)],
        ])->assertSessionHasErrors('files');

        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Two holes',
            'files' => [$this->png(true, 80, 90, 250, 250), $this->png(true, 80, 90, 250, 250)],
        ])->assertSessionHasErrors('files');

        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'One file',
            'files' => [$this->png(true, 80, 90, 250, 250)],
        ])->assertSessionHasErrors('files');

        $this->assertSame(['Default'], StickerTemplate::query()->pluck('name')->all());
    }

    public function test_a_98_percent_transparent_hole_is_still_detected(): void
    {
        // Design tools routinely leave the "transparent" hole at 97-99%
        // transparency (soft erasers, layer opacity, export quantization).
        // Requiring exactly 100% made those holes invisible to the detector.
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Soft eraser',
            'files' => [$this->png(false), $this->png(true, 80, 90, 250, 250, holeAlpha: 125)],
        ])->assertRedirect('/admin/sticker-templates')->assertSessionHasNoErrors();

        $created = StickerTemplate::query()->where('name', 'Soft eraser')->sole();
        $this->assertSame([80, 90, 250], [$created->qr_x, $created->qr_y, $created->qr_size]);
    }

    public function test_a_high_resolution_upload_with_a_small_hole_reports_the_measured_size(): void
    {
        // A 2x print-resolution export: 200px is a generous hole in source
        // pixels but only ~100px once scaled to the 638x1011 canvas. The
        // error must say what was found, not just "no hole".
        $response = $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'High res',
            'files' => [
                $this->png(false),
                $this->png(true, 400, 500, 200, 200, canvasWidth: 1276, canvasHeight: 2022),
            ],
        ]);

        $response->assertInvalid(['files' => 'print canvas']);
        $message = session('errors')->first('files');
        $this->assertMatchesRegularExpression('/was \d+×\d+px/u', $message);
        $this->assertStringContainsString('at least 120×120px', $message);
        $this->assertSame(['Default'], StickerTemplate::query()->pluck('name')->all());
    }

    public function test_a_flattened_export_gets_the_no_transparency_message(): void
    {
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Flattened',
            'files' => [$this->png(false), $this->png(false)],
        ])->assertInvalid(['files' => 'contains fully transparent pixels']);
    }

    public function test_failed_replace_keeps_the_previous_files_and_box(): void
    {
        $template = StickerTemplate::query()->sole();
        $oldFront = $template->front_path;
        $oldBack = $template->back_path;

        // Two hole-less files cannot both be the design side.
        $this->actingAs($this->admin())->patch('/admin/sticker-templates/'.$template->id, [
            'files' => [$this->png(false), $this->png(false)],
        ])->assertSessionHasErrors('files');

        $fresh = $template->fresh();
        $this->assertSame($oldFront, $fresh->front_path);
        $this->assertSame($oldBack, $fresh->back_path);
        $this->assertSame([106, 77, 426], [$fresh->qr_x, $fresh->qr_y, $fresh->qr_size]);
        Storage::disk('public')->assertExists($oldBack);
    }

    public function test_replacing_with_a_plain_file_updates_the_design_side_and_keeps_the_box(): void
    {
        $template = StickerTemplate::query()->sole();
        $oldBack = $template->back_path;

        $this->actingAs($this->admin())->patch('/admin/sticker-templates/'.$template->id, [
            'files' => [$this->png(false, red: 10, green: 80, blue: 10)],
        ])->assertSessionHasNoErrors();

        $fresh = $template->fresh();
        $this->assertSame($oldBack, $fresh->back_path);
        $this->assertSame([106, 77, 426], [$fresh->qr_x, $fresh->qr_y, $fresh->qr_size]);
        $this->assertNotSame($template->front_path, $fresh->front_path);
    }

    public function test_replacing_with_a_holed_file_updates_the_qr_side_and_redetects_the_box(): void
    {
        $template = StickerTemplate::query()->sole();
        $oldFront = $template->front_path;
        $oldBack = $template->back_path;

        $this->actingAs($this->admin())->patch('/admin/sticker-templates/'.$template->id, [
            'files' => [$this->png(true, 60, 70, 220, 220)],
        ])->assertSessionHasNoErrors();

        $fresh = $template->fresh();
        $this->assertSame($oldFront, $fresh->front_path);
        $this->assertNotSame($oldBack, $fresh->back_path);
        $this->assertSame([60, 70, 220], [$fresh->qr_x, $fresh->qr_y, $fresh->qr_size]);
        Storage::disk('public')->assertMissing($oldBack);
        Storage::disk('public')->assertExists($fresh->back_path);
    }

    public function test_activating_another_template_leaves_exactly_one_active(): void
    {
        $other = $this->extraTemplate();

        $this->actingAs($this->admin())
            ->post('/admin/sticker-templates/'.$other->id.'/activate')
            ->assertRedirect('/admin/sticker-templates');

        $this->assertSame($other->id, StickerTemplate::active()?->id);
        $this->assertSame(1, StickerTemplate::query()->where('is_active', true)->count());
    }

    public function test_the_active_or_last_template_cannot_be_deleted(): void
    {
        $default = StickerTemplate::query()->sole();

        $this->actingAs($this->admin())
            ->delete('/admin/sticker-templates/'.$default->id)
            ->assertRedirect('/admin/sticker-templates')
            ->assertSessionHasErrors('template');

        $this->assertTrue(StickerTemplate::query()->whereKey($default->id)->exists());

        $other = $this->extraTemplate();
        $this->actingAs($this->admin())
            ->delete('/admin/sticker-templates/'.$other->id)
            ->assertSessionHasNoErrors();

        $this->assertFalse(StickerTemplate::query()->whereKey($other->id)->exists());
        Storage::disk('public')->assertMissing($other->front_path);
        Storage::disk('public')->assertMissing($other->back_path);
    }

    public function test_library_routes_use_the_focused_controller_and_require_authentication(): void
    {
        $this->assertSame(
            StickerTemplateController::class.'@index',
            Route::getRoutes()->getByName('admin.sticker-templates.index')->getActionName()
        );
        $this->get('/admin/sticker-templates')->assertRedirect('/admin/login');
        $this->post('/admin/sticker-templates', ['name' => 'Nope'])->assertRedirect('/admin/login');

        $this->actingAs($this->admin())
            ->get('/admin/sticker-templates')
            ->assertOk()
            ->assertSee('Sticker templates')
            ->assertSee('Default');
    }

    public function test_a_custom_template_places_the_qr_inside_its_detected_hole(): void
    {
        $template = $this->extraTemplate(80, 90, 250, 250);
        $card = $this->card('CARD-0025');
        $back = imagecreatefromstring(app(CardReviewStickerService::class)->backPng($card, $template));
        $qr = imagecreatefromstring(app(QrCodeService::class)->pngForCard($card, 250));

        $this->assertInstanceOf(GdImage::class, $back);
        $this->assertInstanceOf(GdImage::class, $qr);

        // The QR is stretched to fill the detected hole exactly — no white ring.
        if (imagesx($qr) !== 250) {
            $qr = imagescale($qr, 250, 250, IMG_NEAREST_NEIGHBOUR);
            $this->assertInstanceOf(GdImage::class, $qr);
        }
        $this->assertTrue($this->samePixels($back, $qr, 80, 90));

        $outside = imagecolorsforindex($back, imagecolorat($back, 20, 20));
        $this->assertSame(['red' => 30, 'green' => 40, 'blue' => 50], [
            'red' => $outside['red'],
            'green' => $outside['green'],
            'blue' => $outside['blue'],
        ]);
        unset($back, $qr);
    }

    public function test_bulk_range_uses_the_chosen_template_not_the_active_one(): void
    {
        $this->card('CARD-0024');
        $this->card('CARD-0025');
        $this->card('CARD-0050');
        $this->card('CARD-0051');
        $chosen = $this->extraTemplate(80, 90, 250, 250);

        $response = $this->actingAs($this->admin())->post('/admin/export/sticker-templates', [
            'from' => 25,
            'to' => 50,
            'template_id' => $chosen->id,
        ])->assertOk();

        $entries = $this->zipEntries($response->getContent());
        $this->assertSame([
            'card-25-back.png',
            'card-25-front.png',
            'card-50-back.png',
            'card-50-front.png',
        ], array_keys($entries));

        $card = Card::query()->where('card_number', 'CARD-0025')->first();
        $expected = app(CardReviewStickerService::class)->backPng($card, $chosen);
        $this->assertSame($expected, $entries['card-25-back.png']);
    }

    public function test_bulk_span_over_200_and_empty_matches_fail_closed(): void
    {
        $template = StickerTemplate::active();

        $this->actingAs($this->admin())->post('/admin/export/sticker-templates', [
            'from' => 1,
            'to' => 201,
            'template_id' => $template->id,
        ])->assertSessionHasErrors('to');

        $this->actingAs($this->admin())->from('/admin')->post('/admin/export/sticker-templates', [
            'from' => 90,
            'to' => 91,
            'template_id' => $template->id,
        ])->assertRedirect('/admin')->assertSessionHasErrors('from');
    }

    public function test_card_review_download_uses_the_active_template_even_when_another_exists(): void
    {
        $this->extraTemplate();
        $card = $this->card('CARD-0050');
        $active = StickerTemplate::active();

        $entries = $this->zipEntries(
            $this->actingAs($this->admin())
                ->get('/admin/cards/'.$card->public_id.'/sticker-template.zip')
                ->assertOk()
                ->getContent()
        );

        $this->assertSame(
            app(CardReviewStickerService::class)->backPng($card, $active),
            $entries['card-50-back.png']
        );

        $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertSee($active->name)
            ->assertSee(route('admin.sticker-templates.index'), false);
    }

    private function extraTemplate(int $x = 80, int $y = 90, int $width = 250, int $height = 250): StickerTemplate
    {
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Extra '.$x,
            'files' => [$this->png(false), $this->png(true, $x, $y, $width, $height)],
        ])->assertSessionHasNoErrors();

        return StickerTemplate::query()->where('name', 'Extra '.$x)->sole();
    }

    private function png(
        bool $withHole,
        int $x = 80,
        int $y = 90,
        int $width = 250,
        int $height = 250,
        int $red = 30,
        int $green = 40,
        int $blue = 50,
        int $canvasWidth = 638,
        int $canvasHeight = 1011,
        int $holeAlpha = 127,
    ): UploadedFile {
        $image = imagecreatetruecolor($canvasWidth, $canvasHeight);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $canvasWidth - 1, $canvasHeight - 1, imagecolorallocatealpha($image, $red, $green, $blue, 0));
        if ($withHole) {
            imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, imagecolorallocatealpha($image, 0, 0, 0, $holeAlpha));
        }
        $path = tempnam(sys_get_temp_dir(), 'sticker-art').'.png';
        imagepng($image, $path);
        unset($image);

        return new UploadedFile($path, 'art.png', 'image/png', null, true);
    }

    private function card(string $number): Card
    {
        return Card::factory()->create(['card_number' => $number]);
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'sticker-admin@example.com'],
            ['name' => 'Sticker Admin', 'password' => Hash::make('correct-horse-1')]
        );
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

    /** @return array<string, string> */
    private function zipEntries(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sticker-lib-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
            ksort($entries);

            return $entries;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}

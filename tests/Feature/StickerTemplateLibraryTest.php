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

    public function test_admin_can_create_a_template_from_a_holed_back(): void
    {
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Green batch',
            'front' => $this->png(false),
            'back' => $this->png(true, 80, 90, 250, 250),
        ])->assertRedirect('/admin/sticker-templates')->assertSessionHasNoErrors();

        $created = StickerTemplate::query()->where('name', 'Green batch')->sole();
        $this->assertFalse($created->is_active);
        $this->assertSame([80, 90, 250], [$created->qr_x, $created->qr_y, $created->qr_size]);
        Storage::disk('public')->assertExists($created->front_path);
        Storage::disk('public')->assertExists($created->back_path);
    }

    public function test_a_back_without_a_usable_hole_is_rejected_and_inserts_nothing(): void
    {
        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'No hole',
            'front' => $this->png(false),
            'back' => $this->png(false),
        ])->assertSessionHasErrors('back');

        $this->actingAs($this->admin())->post('/admin/sticker-templates', [
            'name' => 'Thin strip',
            'front' => $this->png(false),
            'back' => $this->png(true, 20, 20, 40, 400),
        ])->assertSessionHasErrors('back');

        $this->assertSame(['Default'], StickerTemplate::query()->pluck('name')->all());
    }

    public function test_failed_back_replace_keeps_the_previous_files_and_box(): void
    {
        $template = StickerTemplate::query()->sole();
        $oldFront = $template->front_path;
        $oldBack = $template->back_path;

        $this->actingAs($this->admin())->patch('/admin/sticker-templates/'.$template->id, [
            'back' => $this->png(false),
        ])->assertSessionHasErrors('back');

        $fresh = $template->fresh();
        $this->assertSame($oldFront, $fresh->front_path);
        $this->assertSame($oldBack, $fresh->back_path);
        $this->assertSame([106, 77, 426], [$fresh->qr_x, $fresh->qr_y, $fresh->qr_size]);
        Storage::disk('public')->assertExists($oldBack);
    }

    public function test_replacing_only_the_front_does_not_change_the_box(): void
    {
        $template = StickerTemplate::query()->sole();
        $oldBack = $template->back_path;

        $this->actingAs($this->admin())->patch('/admin/sticker-templates/'.$template->id, [
            'front' => $this->png(false, red: 10, green: 80, blue: 10),
        ])->assertSessionHasNoErrors();

        $fresh = $template->fresh();
        $this->assertSame($oldBack, $fresh->back_path);
        $this->assertSame([106, 77, 426], [$fresh->qr_x, $fresh->qr_y, $fresh->qr_size]);
        $this->assertNotSame($template->front_path, $fresh->front_path);
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
        $qr = imagecreatefromstring(app(QrCodeService::class)->pngForCard($card, 234));

        $this->assertInstanceOf(GdImage::class, $back);
        $this->assertInstanceOf(GdImage::class, $qr);

        $x = 80 + intdiv(250 - imagesx($qr), 2);
        $y = 90 + intdiv(250 - imagesy($qr), 2);
        $this->assertTrue($this->samePixels($back, $qr, $x, $y));

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
            'front' => $this->png(false),
            'back' => $this->png(true, $x, $y, $width, $height),
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
    ): UploadedFile {
        $image = imagecreatetruecolor(638, 1011);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 637, 1010, imagecolorallocatealpha($image, $red, $green, $blue, 0));
        if ($withHole) {
            imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
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

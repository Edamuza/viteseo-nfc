<?php

namespace Tests\Feature;

use App\Exceptions\UnreadableImageException;
use App\Services\ImageIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageIngestTest extends TestCase
{
    use RefreshDatabase;

    /** A real PNG, so GD has something genuine to decode. */
    private function png(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 70));

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'photo.png', 'image/png', null, true);
    }

    /**
     * A PNG that passes Laravel's image/mimes/dimensions rules but fails GD decode.
     *
     * getimagesize() only needs the 8-byte signature and the IHDR chunk;
     * imagecreatefromstring() needs the full pixel data. 100 bytes is enough
     * for the header on a 40×40 PNG and short of a complete image (full file
     * is ~129 bytes).
     */
    private function truncatedPng(): UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 70));

        $full = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        imagepng($image, $full);
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, substr((string) file_get_contents($full), 0, 100));
        unlink($full);

        return new UploadedFile($path, 'payload.png', 'image/png', null, true);
    }

    public function test_the_stored_file_is_a_jpeg_whatever_was_uploaded(): void
    {
        Storage::fake('public');

        $path = app(ImageIngest::class)->store($this->png(400, 300), 'contact-photos', 800);

        $this->assertStringStartsWith('contact-photos/', $path);
        $this->assertStringEndsWith('.jpg', $path);

        $stored = Storage::disk('public')->get($path);
        $this->assertSame('image/jpeg', (string) (getimagesizefromstring($stored)['mime'] ?? ''));
    }

    public function test_the_filename_carries_nothing_from_the_upload(): void
    {
        Storage::fake('public');

        $file = $this->png(40, 40);
        $path = app(ImageIngest::class)->store($file, 'contact-photos', 800);

        $this->assertStringNotContainsString('photo', basename($path), 'the uploaded name must not reach the filesystem');
        $this->assertMatchesRegularExpression('#^contact-photos/[0-9a-f]{32}\.jpg$#', $path);
    }

    public function test_an_oversized_image_is_scaled_to_the_requested_longest_edge(): void
    {
        Storage::fake('public');

        $path = app(ImageIngest::class)->store($this->png(1200, 600), 'profile-avatars', 512);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame(512, $width, 'the long edge is capped');
        $this->assertSame(256, $height, 'the aspect ratio is preserved');
    }

    public function test_an_image_within_the_cap_is_left_at_its_own_size(): void
    {
        Storage::fake('public');

        $path = app(ImageIngest::class)->store($this->png(200, 100), 'profile-avatars', 512);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame([200, 100], [$width, $height]);
    }

    public function test_a_non_image_is_rejected_with_a_domain_exception(): void
    {
        Storage::fake('public');

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, "<?php echo 'not an image';");
        $file = new UploadedFile($path, 'payload.png', 'image/png', null, true);

        $this->expectException(UnreadableImageException::class);

        app(ImageIngest::class)->store($file, 'contact-photos', 800);
    }

    public function test_a_rejected_upload_stores_nothing(): void
    {
        Storage::fake('public');

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, 'still not an image');
        $file = new UploadedFile($path, 'payload.png', 'image/png', null, true);

        try {
            app(ImageIngest::class)->store($file, 'contact-photos', 800);
        } catch (UnreadableImageException) {
            // expected
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_plainly_bogus_contact_photo_is_rejected_by_validation(): void
    {
        Storage::fake('public');

        $card  = app(\App\Services\CardGenerationService::class)->generate(1)->first();
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'a@example.com'],
            ['name' => 'A', 'password' => \Illuminate\Support\Facades\Hash::make('correct-horse-1')]
        );

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, 'not an image');

        $this->actingAs($admin)
            ->patch('/admin/cards/'.$card->public_id.'/contact', [
                'first_name' => 'John',
                'photo'      => new UploadedFile($path, 'payload.png', 'image/png', null, true),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('photo');
    }

    public function test_a_truncated_contact_photo_is_a_validation_error_from_the_decoder(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('contact-photos/existing.jpg', 'kept');

        $card = app(\App\Services\CardGenerationService::class)->generate(1)->first();
        $profile = $card->contactProfile()->make(['first_name' => 'John']);
        $profile->photo_path = 'contact-photos/existing.jpg';
        $card->contactProfile()->save($profile);

        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'a@example.com'],
            ['name' => 'A', 'password' => \Illuminate\Support\Facades\Hash::make('correct-horse-1')]
        );

        $this->actingAs($admin)
            ->patch('/admin/cards/'.$card->public_id.'/contact', [
                'first_name' => 'John',
                'photo'      => $this->truncatedPng(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['photo' => 'That file is not a readable image.']);

        $this->assertSame('contact-photos/existing.jpg', $card->fresh()->contactProfile->photo_path);
        $this->assertTrue(Storage::disk('public')->exists('contact-photos/existing.jpg'));
    }
}

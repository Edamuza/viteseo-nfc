<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CardProfileController;
use App\Exceptions\UnreadableImageException;
use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'profile-manager@example.com'],
            ['name' => 'Profile Manager', 'password' => Hash::make('correct-horse-1')]
        );
    }

    private function card(array $profile = []): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        if ($profile !== []) {
            $card->profile()->save($card->profile()->make($profile));
        }

        return $card->fresh();
    }

    private function image(int $red, int $green, int $blue, int $width = 900, int $height = 600): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $colour = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $colour);
        $path = tempnam(sys_get_temp_dir(), 'profile-avatar').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
    }

    public function test_admin_can_save_all_identity_and_welcome_fields(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => "Uriel\r\nPapa",
            'headline' => "Chief Executive\x00 Officer",
            'username' => 'yurielyel',
            'location' => 'Philippines',
            'tagline' => 'Say my name',
            'welcome_eyebrow' => 'Heya, partners!',
            'welcome_heading' => 'Grow your brand',
            'welcome_body' => "First paragraph.\r\n\r\nSecond paragraph.",
            'welcome_cta_label' => 'Book a consultation',
            'welcome_cta_url' => 'https://viteseo.ph/book',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Profile details saved.');

        $profile = $card->fresh()->profile;
        $this->assertSame('UrielPapa', $profile->display_name);
        $this->assertSame('Chief Executive Officer', $profile->headline);
        $this->assertSame('yurielyel', $profile->username);
        $this->assertSame('Philippines', $profile->location);
        $this->assertSame('Say my name', $profile->tagline);
        $this->assertSame('Heya, partners!', $profile->welcome_eyebrow);
        $this->assertSame('Grow your brand', $profile->welcome_heading);
        $this->assertSame("First paragraph.\n\nSecond paragraph.", $profile->welcome_body);
        $this->assertSame('Book a consultation', $profile->welcome_cta_label);
        $this->assertSame('https://viteseo.ph/book', $profile->welcome_cta_url);
    }

    public function test_profile_field_limits_and_unsafe_cta_urls_are_rejected(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', [
                'display_name' => 'Uriel Papa',
                'headline' => str_repeat('h', 201),
                'welcome_body' => str_repeat('b', 601),
                'welcome_cta_url' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors(['headline', 'welcome_body', 'welcome_cta_url']);

        $this->assertNull($card->fresh()->profile);
    }

    public function test_avatar_upload_is_reencoded_scaled_and_derives_the_brand_colour(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/old.jpg', 'old-avatar');
        $card = $this->card(['display_name' => 'Uriel Papa']);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/old.jpg',
            'brand_color' => '#123456',
        ])->save();

        $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Uriel Papa',
            'avatar' => $this->image(220, 30, 40),
        ])->assertSessionHasNoErrors();

        $profile = $card->fresh()->profile;
        Storage::disk('public')->assertMissing('profile-avatars/old.jpg');
        Storage::disk('public')->assertExists($profile->avatar_path);
        $this->assertMatchesRegularExpression('#^profile-avatars/[0-9a-f]{32}\.jpg$#', $profile->avatar_path);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $profile->brand_color);
        $this->assertNotSame('#123456', $profile->brand_color);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($profile->avatar_path));
        $this->assertSame([512, 341], [$width, $height]);
    }

    public function test_failed_avatar_storage_keeps_the_previous_avatar_and_colour(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/old.jpg', 'old-avatar');
        $card = $this->card(['display_name' => 'Uriel Papa']);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/old.jpg',
            'brand_color' => '#123456',
        ])->save();

        $images = \Mockery::mock(\App\Services\ImageIngest::class);
        $images->shouldReceive('storeWithInspection')
            ->once()
            ->andThrow(new UnreadableImageException('Could not store the processed image.'));
        $this->app->instance(\App\Services\ImageIngest::class, $images);

        $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Uriel Papa',
            'avatar' => $this->image(220, 30, 40),
        ])->assertSessionHasErrors('avatar');

        $profile = $card->fresh()->profile;
        $this->assertSame('profile-avatars/old.jpg', $profile->avatar_path);
        $this->assertSame('#123456', $profile->brand_color);
        Storage::disk('public')->assertExists('profile-avatars/old.jpg');
    }

    public function test_partial_profile_patch_does_not_erase_an_omitted_welcome_body(): void
    {
        $card = $this->card([
            'display_name' => 'Uriel Papa',
            'welcome_body' => 'Keep this global welcome.',
        ]);

        $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Yuriel Yel',
        ])->assertSessionHasNoErrors();

        $profile = $card->fresh()->profile;
        $this->assertSame('Yuriel Yel', $profile->display_name);
        $this->assertSame('Keep this global welcome.', $profile->welcome_body);
    }

    public function test_deleting_only_the_avatar_clears_the_colour_and_file_but_keeps_the_profile(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/avatar.jpg', 'avatar');
        $card = $this->card(['display_name' => 'Uriel Papa', 'headline' => 'CEO']);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/avatar.jpg',
            'brand_color' => '#6b2020',
        ])->save();

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/profile/avatar')
            ->assertSessionHas('status', 'Profile avatar removed.');

        $profile = $card->fresh()->profile;
        $this->assertSame('Uriel Papa', $profile->display_name);
        $this->assertSame('CEO', $profile->headline);
        $this->assertNull($profile->avatar_path);
        $this->assertNull($profile->brand_color);
        Storage::disk('public')->assertMissing('profile-avatars/avatar.jpg');
    }

    public function test_avatar_delete_clears_an_inconsistent_colour_even_when_the_path_is_missing(): void
    {
        $card = $this->card(['display_name' => 'Uriel Papa']);
        $card->profile->forceFill(['avatar_path' => null, 'brand_color' => '#6b2020'])->save();

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/profile/avatar')
            ->assertSessionHas('status', 'Profile avatar removed.');

        $this->assertNull($card->fresh()->profile->brand_color);
    }

    public function test_existing_avatar_has_a_preview_and_avatar_only_delete_form(): void
    {
        $card = $this->card(['display_name' => 'Uriel Papa']);
        $card->profile->forceFill(['avatar_path' => 'profile-avatars/avatar.jpg'])->save();

        $body = $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id.'?edit=1')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('storage/profile-avatars/avatar.jpg', $body);
        $this->assertStringContainsString(
            'action="'.route('admin.cards.profile.avatar.delete', $card->public_id).'"',
            $body
        );
        $this->assertMatchesRegularExpression(
            '#action="'.preg_quote(route('admin.cards.profile.avatar.delete', $card->public_id), '#').'".*?name="_method"\s+value="DELETE"#s',
            $body
        );
    }

    public function test_request_data_cannot_set_avatar_path_or_brand_colour(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Uriel Papa',
            'avatar_path' => '../../etc/passwd',
            'brand_color' => '#ff0000',
        ]);

        $this->assertNull($card->fresh()->profile->avatar_path);
        $this->assertNull($card->fresh()->profile->brand_color);
    }

    public function test_profile_routes_use_the_focused_controller_and_require_authentication(): void
    {
        $card = $this->card(['display_name' => 'Uriel Papa']);

        $this->assertStringContainsString(
            CardProfileController::class,
            Route::getRoutes()->getByName('admin.cards.profile.update')->getActionName()
        );
        $this->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => 'Intruder'])
            ->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/profile/avatar')
            ->assertRedirect('/admin/login');

        $this->assertSame('Uriel Papa', $card->fresh()->profile->display_name);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use App\Services\VCardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contact mode as a phone sees it: a tap hands over the contact itself, with
 * a face, and whatever the owner left blank filled from their profile page.
 */
class ContactCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function card(string $mode = 'contact'): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->forceFill(['nfc_mode' => $mode])->save();

        return $card;
    }

    private function withContact(Card $card, array $attrs): Card
    {
        $card->contactProfile()->save($card->contactProfile()->make($attrs));

        return $card->fresh();
    }

    private function withProfile(Card $card, array $attrs = []): Card
    {
        $profile = $card->profile()->make(array_merge(['display_name' => 'Uriel Papa'], $attrs));
        $profile->forceFill(array_intersect_key($attrs, ['avatar_path' => true]));
        $card->profile()->save($profile);

        return $card->fresh();
    }

    private function tap(Card $card): string
    {
        return $this->get('/n/'.$card->public_id)->assertOk()->getContent();
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 40));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int} width and height of the embedded photo */
    private function embeddedPhotoSize(string $vcf): array
    {
        $unfolded = str_replace("\r\n ", '', $vcf);
        $this->assertSame(1, preg_match('/^PHOTO;ENCODING=b;TYPE=JPEG:([A-Za-z0-9+\/=]+)\r$/m', $unfolded, $m), 'no PHOTO line');

        $size = getimagesizefromstring((string) base64_decode($m[1], true));
        $this->assertIsArray($size, 'the PHOTO is not a readable image');

        return [$size[0], $size[1]];
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function unlock(Card $card): static
    {
        return $this->withSession(['nfc-owner:'.$card->public_id => now()->timestamp]);
    }

    // ── The tap ─────────────────────────────────────────────────────────────

    public function test_a_tap_is_the_contact_itself_served_inline(): void
    {
        $card = $this->withContact($this->card(), ['first_name' => 'John', 'last_name' => 'Doe']);

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/vcard; charset=utf-8')
            // Inline is what makes an iPhone show its contact sheet rather
            // than asking whether to download a file.
            ->assertHeader('Content-Disposition', 'inline; filename="John-Doe.vcf"')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_the_tap_and_the_download_are_the_same_contact(): void
    {
        $card = $this->withContact($this->card(), ['first_name' => 'John', 'work_email' => 'j@example.com']);

        $strip = fn (string $vcf) => preg_replace('/^REV:.*$/m', '', $vcf);

        $this->assertSame(
            $strip($this->tap($card)),
            $strip($this->get('/n/'.$card->public_id.'/contact.vcf')->assertOk()->getContent())
        );
    }

    public function test_a_contact_card_with_nothing_to_give_still_shows_the_setup_chooser(): void
    {
        $card = $this->card();

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('What should your card do?');
    }

    // ── Filled from the profile page ────────────────────────────────────────

    public function test_a_contact_card_with_no_details_of_its_own_uses_the_profile_page(): void
    {
        $card = $this->withProfile($this->card(), [
            'headline' => 'Owner, Acme Inc.',
            'phone'    => '+639170000001',
            'email'    => 'uriel@example.com',
            'tagline'  => 'Always happy to connect',
        ]);
        $card->nfcLinks()->create(['title' => 'Site', 'url' => 'https://acme.example', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'FB', 'url' => 'https://facebook.com/acme', 'sort_order' => 1, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Off', 'url' => 'https://hidden.example', 'sort_order' => 2, 'is_active' => false]);

        $vcf = $this->tap($card);

        $this->assertStringContainsString('FN:Uriel Papa', $vcf);
        $this->assertStringContainsString('TITLE:Owner', $vcf);
        $this->assertStringContainsString('ORG:Acme Inc.', $vcf);
        $this->assertStringContainsString('TEL;TYPE=CELL,VOICE:+639170000001', $vcf);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET,HOME:uriel@example.com', $vcf);
        $this->assertStringContainsString('URL:https://acme.example', $vcf);
        $this->assertStringContainsString('URL:https://facebook.com/acme', $vcf);
        $this->assertStringNotContainsString('hidden.example', $vcf, 'a switched-off link was handed out');
        $this->assertStringContainsString('NOTE:Always happy to connect', $vcf);
    }

    public function test_the_contact_cards_own_details_win_and_blanks_fill_a_group_at_a_time(): void
    {
        $card = $this->withProfile($this->card(), ['phone' => '+639170000001', 'email' => 'uriel@example.com']);
        $card = $this->withContact($card, ['first_name' => 'John', 'last_name' => 'Doe', 'work_phone' => '+63 2 8123 4567']);

        $vcf = $this->tap($card);

        $this->assertStringContainsString('FN:John Doe', $vcf);
        $this->assertStringNotContainsString('Uriel', $vcf);
        $this->assertStringContainsString('TEL;TYPE=WORK,VOICE:+63 2 8123 4567', $vcf);
        $this->assertStringNotContainsString('+639170000001', $vcf, 'the card has a phone of its own, so the profile\'s must not join it');
        $this->assertStringContainsString('uriel@example.com', $vcf, 'the card has no email, so the profile\'s fills it');
    }

    public function test_nothing_is_copied_so_editing_the_profile_changes_the_contact(): void
    {
        $card = $this->withProfile($this->card(), ['phone' => '+639170000001']);
        $this->assertStringContainsString('+639170000001', $this->tap($card));

        $card->profile->update(['phone' => '+639170000002']);

        $this->assertStringContainsString('+639170000002', $this->tap($card->fresh()));
        $this->assertNull($card->fresh()->contactProfile, 'a tap must never write a contact row');
    }

    public function test_links_are_only_used_when_the_contact_has_no_web_address_of_its_own(): void
    {
        $card = $this->withProfile($this->card());
        $card = $this->withContact($card, ['website' => 'https://own.example']);
        $card->nfcLinks()->create(['title' => 'Site', 'url' => 'https://acme.example', 'sort_order' => 0, 'is_active' => true]);

        $vcf = $this->tap($card->fresh());

        $this->assertStringContainsString('URL:https://own.example', $vcf);
        $this->assertStringNotContainsString('acme.example', $vcf);
    }

    public function test_a_profile_without_a_name_does_not_make_a_contact(): void
    {
        $card = $this->withProfile($this->card(), ['display_name' => '   ', 'phone' => '+639170000001']);

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('What should your card do?');
    }

    public function test_a_named_profile_configures_contact_mode_in_php_and_sql_alike(): void
    {
        $named = $this->withProfile($this->card());
        $blank = $this->withProfile($this->card(), ['display_name' => '']);

        $this->assertTrue($named->nfcModeIsConfigured());
        $this->assertTrue(Card::query()->whereNfcModeConfigured()->whereKey($named->id)->exists());
        $this->assertFalse(Card::query()->whereNfcModeConfigured(false)->whereKey($named->id)->exists());

        $this->assertFalse($blank->nfcModeIsConfigured());
        $this->assertFalse(Card::query()->whereNfcModeConfigured()->whereKey($blank->id)->exists());
        $this->assertTrue(Card::query()->whereNfcModeConfigured(false)->whereKey($blank->id)->exists());
    }

    // ── The photo ───────────────────────────────────────────────────────────

    public function test_the_contact_photo_is_embedded_small_and_folded(): void
    {
        Storage::disk('public')->put('contact-photos/me.jpg', $this->jpeg(800, 600));
        $card = $this->withContact($this->card(), ['first_name' => 'John']);
        $card->contactProfile->forceFill(['photo_path' => 'contact-photos/me.jpg'])->save();

        $vcf = $this->tap($card->fresh());

        [$width, $height] = $this->embeddedPhotoSize($vcf);
        $this->assertSame(VCardBuilder::PHOTO_EDGE, max($width, $height));
        $this->assertSame(300, $height, 'the photo was not scaled in proportion');

        foreach (explode("\r\n", $vcf) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'a line was not folded: '.substr($line, 0, 30));
        }
    }

    public function test_the_profile_avatar_is_used_when_the_contact_has_no_photo(): void
    {
        Storage::disk('public')->put('profile-avatars/face.jpg', $this->jpeg(200, 200));
        $card = $this->withProfile($this->card(), ['avatar_path' => 'profile-avatars/face.jpg']);

        $this->assertSame([200, 200], $this->embeddedPhotoSize($this->tap($card)));
    }

    public function test_a_missing_photo_file_is_left_out_rather_than_breaking_the_card(): void
    {
        $card = $this->withContact($this->card(), ['first_name' => 'John']);
        $card->contactProfile->forceFill(['photo_path' => 'contact-photos/gone.jpg'])->save();

        $vcf = $this->tap($card->fresh());

        $this->assertStringContainsString('FN:John', $vcf);
        $this->assertStringNotContainsString('PHOTO', $vcf);
    }

    public function test_the_profile_pages_save_contact_carries_the_avatar_too(): void
    {
        Storage::disk('public')->put('profile-avatars/face.jpg', $this->jpeg(120, 90));
        $card = $this->withProfile($this->card('profile'), ['avatar_path' => 'profile-avatars/face.jpg']);

        $vcf = $this->get('/n/'.$card->public_id.'/profile.vcf')->assertOk()->getContent();

        $this->assertSame([120, 90], $this->embeddedPhotoSize($vcf));
    }

    // ── The owner's setup form ──────────────────────────────────────────────

    private function saveSetup(Card $card, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->unlock($card)->post('/n/'.$card->public_id.'/setup/contact', $fields + [
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);
    }

    public function test_the_owner_can_add_a_photo_at_setup(): void
    {
        $card = $this->card('links');

        $this->saveSetup($card, [
            'first_name' => 'Maria',
            'photo'      => UploadedFile::fake()->image('me.jpg', 600, 600),
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $path = $card->fresh()->contactProfile->photo_path;
        $this->assertStringStartsWith('contact-photos/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame([400, 400], $this->embeddedPhotoSize($this->tap($card->fresh())));
    }

    public function test_replacing_the_photo_deletes_the_old_file(): void
    {
        $card = $this->card('links');
        $this->saveSetup($card, ['first_name' => 'Maria', 'photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $first = $card->fresh()->contactProfile->photo_path;

        $this->saveSetup($card, ['first_name' => 'Maria', 'photo' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
        $second = $card->fresh()->contactProfile->photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_the_owner_can_remove_the_photo(): void
    {
        $card = $this->card('links');
        $this->saveSetup($card, ['first_name' => 'Maria', 'photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $path = $card->fresh()->contactProfile->photo_path;

        $this->saveSetup($card, ['first_name' => 'Maria', 'remove_photo' => '1'])->assertRedirect();

        $this->assertNull($card->fresh()->contactProfile->photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_saving_without_touching_the_photo_keeps_it(): void
    {
        $card = $this->card('links');
        $this->saveSetup($card, ['first_name' => 'Maria', 'photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $path = $card->fresh()->contactProfile->photo_path;

        $this->saveSetup($card, ['first_name' => 'Maria Clara']);

        $this->assertSame($path, $card->fresh()->contactProfile->photo_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_card_with_a_named_profile_needs_no_name_on_the_contact_form(): void
    {
        $card = $this->withProfile($this->card('profile'));

        $this->saveSetup($card, [])->assertRedirect('/n/'.$card->public_id.'/done');

        $this->assertStringContainsString('FN:Uriel Papa', $this->tap($card->fresh()));
    }

    public function test_a_card_without_a_profile_still_needs_a_name(): void
    {
        $card = $this->card('links');

        $this->saveSetup($card, [])->assertSessionHasErrors('first_name');
    }

    public function test_an_upload_without_the_pin_never_reaches_the_disk(): void
    {
        $card = $this->card('links');

        $this->post('/n/'.$card->public_id.'/setup/contact', [
            'activation_pin' => '999999',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'first_name' => 'Mallory',
            'photo' => UploadedFile::fake()->image('x.jpg', 300, 300),
        ])->assertSessionHasErrors('activation_pin');

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($card->fresh()->contactProfile);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $card = $this->card('links');

        $this->saveSetup($card, [
            'first_name' => 'Maria',
            'photo' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('photo');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_setup_form_shows_what_the_profile_will_fill_in(): void
    {
        $card = $this->withProfile($this->card('profile'), ['phone' => '+639170000001']);

        $this->unlock($card)->get('/n/'.$card->public_id.'/setup/contact')
            ->assertOk()
            ->assertSee('Anything you leave blank comes from your profile page')
            ->assertSee('data-fallback="Uriel Papa"', false)
            ->assertSee('placeholder="+639170000001"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }

    // ── Where the owner goes now there is no page ───────────────────────────

    public function test_the_done_page_gives_a_contact_card_owner_the_way_back_in(): void
    {
        $card = $this->withContact($this->card(), ['first_name' => 'John']);

        $this->get('/n/'.$card->public_id.'/done')
            ->assertOk()
            ->assertSee(route('nfc.manage', $card->public_id));
    }

    public function test_the_admin_sees_a_profile_filled_contact_card_as_live(): void
    {
        Storage::disk('public')->put('profile-avatars/face.jpg', $this->jpeg(50, 50));
        $card = $this->withProfile($this->card(), ['avatar_path' => 'profile-avatars/face.jpg']);

        $this->actingAs($this->admin())->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertSee('Contact card live')
            ->assertSee('Using the profile photo');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\NfcProfileEdit;
use App\Models\SitePage;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NfcProfileEditTest extends TestCase
{
    use RefreshDatabase;

    private function card(array $profile = ['display_name' => 'Uriel Papa']): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->forceFill(['nfc_mode' => NfcMode::Profile])->save();
        $card->profile()->save($card->profile()->make($profile));

        return $card->fresh();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'profile-revert@example.com',
            'password' => Hash::make('correct-horse-1'),
        ]);
    }

    private function unlock(Card $card): void
    {
        $this->post('/n/'.$card->public_id.'/edit/unlock', ['activation_pin' => '000000'])
            ->assertRedirect('/n/'.$card->public_id.'/edit');
    }

    private function image(int $red = 140, int $green = 40, int $blue = 30): UploadedFile
    {
        $image = imagecreatetruecolor(900, 600);
        $colour = imagecolorallocate($image, $red, $green, $blue);
        imagefilledrectangle($image, 0, 0, 899, 599, $colour);
        $path = tempnam(sys_get_temp_dir(), 'client-profile-avatar').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
    }

    public function test_an_empty_profile_card_offers_the_owner_the_self_service_setup_link(): void
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->forceFill(['nfc_mode' => NfcMode::Profile])->save();

        // The tap lands on "not set up yet", but the owner must be able to
        // reach the PIN-protected editor from there — otherwise a fresh
        // Profile-mode card is a dead end the customer cannot configure.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Not set up yet')
            ->assertSee('/n/'.$card->public_id.'/edit');

        // And the editor genuinely works from empty: unlock, save, live page.
        $this->unlock($card->fresh());
        $this->patch('/n/'.$card->public_id.'/edit', ['display_name' => 'Fresh Owner'])
            ->assertSessionHasNoErrors();
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('Fresh Owner');
    }

    public function test_admin_only_modes_do_not_offer_the_profile_setup_link(): void
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->forceFill(['nfc_mode' => NfcMode::Contact])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Not set up yet')
            ->assertDontSee('/n/'.$card->public_id.'/edit');
    }

    public function test_a_card_has_newest_first_profile_edit_history_with_array_snapshots(): void
    {
        $card = $this->card();
        $old = $card->profileEdits()->create([
            'ip_address' => '203.0.113.1',
            'user_agent' => 'Old browser',
            'snapshot' => ['profile' => ['display_name' => 'Before'], 'links' => []],
        ]);
        $new = $card->profileEdits()->create([
            'ip_address' => '2001:db8::1',
            'user_agent' => null,
            'snapshot' => ['profile' => ['display_name' => 'After'], 'links' => []],
        ]);
        $old->forceFill(['created_at' => now()->subMinute()])->save();

        $history = $card->fresh()->profileEdits;

        $this->assertSame([$new->id, $old->id], $history->pluck('id')->all());
        $this->assertSame('After', $history->first()->snapshot['profile']['display_name']);
    }

    public function test_deleting_a_card_cascades_its_profile_edit_history(): void
    {
        $card = $this->card();
        $edit = $card->profileEdits()->create([
            'ip_address' => '203.0.113.1',
            'snapshot' => ['profile' => null, 'links' => []],
        ]);

        $card->delete();

        $this->assertFalse(NfcProfileEdit::query()->whereKey($edit->id)->exists());
    }

    public function test_profile_edit_page_is_reachable_only_in_profile_mode(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => NfcMode::Links])->save();

        $this->get('/n/'.$card->public_id.'/edit')->assertNotFound();
        $this->post('/n/'.$card->public_id.'/edit/unlock', ['activation_pin' => '000000'])->assertNotFound();
    }

    public function test_locked_profile_edit_page_shows_only_the_pin_gate_with_private_headers(): void
    {
        $card = $this->card();

        $response = $this->get('/n/'.$card->public_id.'/edit')
            ->assertOk()
            ->assertSee('name="activation_pin"', false)
            ->assertDontSee('name="display_name"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('000000', $response->getContent());
    }

    public function test_wrong_pin_never_unlocks_and_the_sixth_attempt_is_rate_limited(): void
    {
        $card = $this->card();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/n/'.$card->public_id.'/edit')
                ->post('/n/'.$card->public_id.'/edit/unlock', ['activation_pin' => '111111'])
                ->assertRedirect('/n/'.$card->public_id.'/edit')
                ->assertSessionHasErrors(['activation_pin' => 'That activation PIN is not correct.']);
        }

        $limited = $this->from('/n/'.$card->public_id.'/edit')
            ->post('/n/'.$card->public_id.'/edit/unlock', ['activation_pin' => '111111'])
            ->assertRedirect('/n/'.$card->public_id.'/edit')
            ->assertSessionHasErrors('activation_pin');

        $this->assertStringContainsString(
            'Too many incorrect attempts',
            $limited->getSession()->get('errors')->first('activation_pin')
        );
        $this->get('/n/'.$card->public_id.'/edit')
            ->assertDontSee('name="display_name"', false)
            ->assertDontSee('111111');
    }

    public function test_correct_pin_unlocks_only_that_cards_editor(): void
    {
        $card = $this->card();
        $other = $this->card(['display_name' => 'Other Person']);

        $this->post('/n/'.$card->public_id.'/edit/unlock', ['activation_pin' => '000000'])
            ->assertRedirect('/n/'.$card->public_id.'/edit');

        $this->get('/n/'.$card->public_id.'/edit')
            ->assertOk()
            ->assertSee('name="display_name"', false)
            ->assertDontSee('name="activation_pin"', false)
            ->assertDontSee('000000');

        $this->get('/n/'.$other->public_id.'/edit')
            ->assertSee('name="activation_pin"', false)
            ->assertDontSee('name="display_name"', false);
    }

    public function test_unlocked_client_update_changes_owned_fields_and_links_and_records_the_pre_edit_snapshot(): void
    {
        $card = $this->card([
            'display_name' => 'Before Name',
            'headline' => 'Before headline',
            'welcome_heading' => 'Admin welcome',
        ]);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/before.jpg',
            'brand_color' => '#112233',
        ])->save();
        $firstLink = $card->nfcLinks()->create([
            'title' => 'Old active',
            'url' => 'https://old.example.com',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $secondLink = $card->nfcLinks()->create([
            'title' => 'Old inactive',
            'url' => 'https://inactive.example.com',
            'sort_order' => 2,
            'is_active' => false,
        ]);
        $this->unlock($card);

        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'After Name',
            'headline' => 'Founder',
            'username' => 'after-name',
            'location' => 'Cebu',
            'tagline' => 'Building useful things.',
            'links' => [
                ['title' => 'Portfolio', 'url' => 'https://portfolio.example.com'],
                ['title' => '', 'url' => ''],
                ['title' => 'LinkedIn', 'url' => 'https://linkedin.com/in/after'],
            ],
        ])->assertRedirect('/n/'.$card->public_id.'/edit')
            ->assertSessionHasNoErrors();

        $profile = $card->fresh()->profile;
        $this->assertSame('After Name', $profile->display_name);
        $this->assertSame('Founder', $profile->headline);
        $this->assertSame('after-name', $profile->username);
        $this->assertSame('Cebu', $profile->location);
        $this->assertSame('Building useful things.', $profile->tagline);
        $this->assertSame('Admin welcome', $profile->welcome_heading);

        $links = $card->fresh()->nfcLinks()->orderBy('sort_order')->get();
        $this->assertSame(['Portfolio', 'LinkedIn'], $links->pluck('title')->all());
        $this->assertSame([0, 1], $links->pluck('sort_order')->all());
        $this->assertSame([true, true], $links->pluck('is_active')->all());

        $edit = $card->fresh()->profileEdits()->sole();
        $this->assertSame('Before Name', $edit->snapshot['profile']['display_name']);
        $this->assertSame('Admin welcome', $edit->snapshot['profile']['welcome_heading']);
        $this->assertSame('profile-avatars/before.jpg', $edit->snapshot['profile']['avatar_path']);
        $this->assertSame('#112233', $edit->snapshot['profile']['brand_color']);
        $this->assertSame(
            [$firstLink->title, $secondLink->title],
            collect($edit->snapshot['links'])->pluck('title')->all()
        );
        $this->assertSame('127.0.0.1', $edit->ip_address);
    }

    public function test_locked_or_wrong_card_session_cannot_update_a_profile(): void
    {
        $card = $this->card(['display_name' => 'Protected']);
        $other = $this->card(['display_name' => 'Other']);
        $this->unlock($other);

        $this->patch('/n/'.$card->public_id.'/edit', ['display_name' => 'Intruder'])
            ->assertForbidden();

        $this->assertSame('Protected', $card->fresh()->profile->display_name);
        $this->assertCount(0, $card->profileEdits);
    }

    public function test_client_payload_cannot_write_admin_fields_or_another_cards_data(): void
    {
        $card = $this->card([
            'display_name' => 'Client',
            'welcome_eyebrow' => 'Admin eyebrow',
            'welcome_heading' => 'Admin heading',
            'welcome_body' => 'Admin body',
            'welcome_cta_label' => 'Admin CTA',
            'welcome_cta_url' => 'https://admin.example.com',
        ]);
        $card->profile->forceFill(['brand_color' => '#123456'])->save();
        $other = $this->card(['display_name' => 'Untouched Other']);
        $otherLink = $other->nfcLinks()->create([
            'title' => 'Other link',
            'url' => 'https://other.example.com',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->unlock($card);

        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Allowed change',
            'welcome_eyebrow' => 'Hacked',
            'welcome_heading' => 'Hacked',
            'welcome_body' => 'Hacked',
            'welcome_cta_label' => 'Hacked',
            'welcome_cta_url' => 'https://hacked.example.com',
            'brand_color' => '#ff0000',
            'card_id' => $other->id,
            'site_pages' => ['privacy' => 'Hacked'],
            'links' => [[
                'id' => $otherLink->id,
                'card_id' => $other->id,
                'title' => 'Mine',
                'url' => 'https://mine.example.com',
                'is_active' => false,
                'sort_order' => 99,
            ]],
        ])->assertSessionHasNoErrors();

        $profile = $card->fresh()->profile;
        $this->assertSame('Allowed change', $profile->display_name);
        $this->assertSame('Admin eyebrow', $profile->welcome_eyebrow);
        $this->assertSame('Admin heading', $profile->welcome_heading);
        $this->assertSame('Admin body', $profile->welcome_body);
        $this->assertSame('Admin CTA', $profile->welcome_cta_label);
        $this->assertSame('https://admin.example.com', $profile->welcome_cta_url);
        $this->assertSame('#123456', $profile->brand_color);
        $this->assertSame('Untouched Other', $other->fresh()->profile->display_name);
        $this->assertSame('Other link', $otherLink->fresh()->title);
        $this->assertSame('Mine', $card->fresh()->nfcLinks()->sole()->title);
    }

    public function test_client_avatar_upload_and_removal_retain_snapshot_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/original.jpg', 'original');
        $card = $this->card(['display_name' => 'Avatar Client']);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/original.jpg',
            'brand_color' => '#123456',
        ])->save();
        $this->unlock($card);

        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Avatar Client',
            'avatar' => $this->image(),
        ])->assertSessionHasNoErrors();

        $uploadedPath = $card->fresh()->profile->avatar_path;
        $this->assertNotSame('profile-avatars/original.jpg', $uploadedPath);
        Storage::disk('public')->assertExists('profile-avatars/original.jpg');
        Storage::disk('public')->assertExists($uploadedPath);
        $this->assertSame('profile-avatars/original.jpg', $card->fresh()->profileEdits()->latest('id')->first()->snapshot['profile']['avatar_path']);

        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Avatar Client',
            'remove_avatar' => '1',
        ])->assertSessionHasNoErrors();

        $profile = $card->fresh()->profile;
        $this->assertNull($profile->avatar_path);
        $this->assertNull($profile->brand_color);
        Storage::disk('public')->assertExists($uploadedPath);
        $this->assertSame($uploadedPath, $card->fresh()->profileEdits()->latest('id')->first()->snapshot['profile']['avatar_path']);
        $this->assertCount(2, $card->fresh()->profileEdits);
    }

    public function test_client_links_are_limited_and_require_safe_external_urls(): void
    {
        $card = $this->card();
        $this->unlock($card);

        $tooMany = array_fill(0, 13, ['title' => 'Link', 'url' => 'https://example.com']);
        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Uriel Papa',
            'links' => $tooMany,
        ])->assertSessionHasErrors('links');

        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Uriel Papa',
            'links' => [['title' => 'Bad', 'url' => 'javascript:alert(1)']],
        ])->assertSessionHasErrors('links.0.url');

        $card->nfcLinks()->create([
            'title' => 'Must survive malformed input',
            'url' => 'https://safe.example.com',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Uriel Papa',
            'links' => 'not-an-array',
        ])->assertSessionHasErrors('links');
        $this->patch('/n/'.$card->public_id.'/edit', [
            'display_name' => 'Uriel Papa',
            'links' => [['title' => "\x7f", 'url' => 'https://safe.example.com']],
        ])->assertSessionHasErrors('links.0.title');

        $this->assertCount(0, $card->fresh()->profileEdits);
        $this->assertSame('Must survive malformed input', $card->fresh()->nfcLinks()->sole()->title);
    }

    public function test_unlocked_editor_renders_owned_fields_avatar_controls_and_progressive_link_rows(): void
    {
        $card = $this->card([
            'display_name' => 'Editor Name',
            'headline' => 'Editor Headline',
            'welcome_heading' => 'Not client-owned',
        ]);
        $card->profile->forceFill(['avatar_path' => 'profile-avatars/editor.jpg'])->save();
        $card->nfcLinks()->create([
            'title' => 'Existing',
            'url' => 'https://existing.example.com',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->unlock($card);

        $response = $this->get('/n/'.$card->public_id.'/edit')
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="display_name"', false)
            ->assertSee('name="headline"', false)
            ->assertSee('name="username"', false)
            ->assertSee('name="location"', false)
            ->assertSee('name="tagline"', false)
            ->assertSee('name="avatar"', false)
            ->assertSee('name="remove_avatar"', false)
            ->assertSee('name="links[0][title]"', false)
            ->assertSee('name="links[0][url]"', false)
            ->assertSee('name="links[1][title]"', false)
            ->assertSee('Add link')
            ->assertSee('profile-avatars/editor.jpg')
            ->assertDontSee('name="welcome_heading"', false)
            ->assertDontSee('name="brand_color"', false);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_only_an_admin_can_restore_the_newest_snapshot_exactly_and_consume_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/restored.jpg', 'restored');
        Storage::disk('public')->put('profile-avatars/current.jpg', 'current');
        $card = $this->card([
            'display_name' => 'Current',
            'headline' => 'Current headline',
            'welcome_heading' => 'Current welcome',
        ]);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/current.jpg',
            'brand_color' => '#334455',
        ])->save();
        $card->nfcLinks()->create([
            'title' => 'Current link',
            'url' => 'https://current.example.com',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $older = $card->profileEdits()->create([
            'ip_address' => '203.0.113.1',
            'snapshot' => ['profile' => null, 'links' => []],
        ]);
        $newest = $card->profileEdits()->create([
            'ip_address' => '203.0.113.2',
            'snapshot' => [
                'profile' => [
                    'display_name' => 'Restored Name',
                    'headline' => 'Restored headline',
                    'username' => 'restored',
                    'location' => 'Manila',
                    'tagline' => 'Restored tagline',
                    'welcome_eyebrow' => 'Restored eyebrow',
                    'welcome_heading' => 'Restored welcome',
                    'welcome_body' => "Restored\nbody",
                    'welcome_cta_label' => 'Restored CTA',
                    'welcome_cta_url' => 'https://restored.example.com/cta',
                    'avatar_path' => 'profile-avatars/restored.jpg',
                    'brand_color' => '#112233',
                ],
                'links' => [
                    ['title' => 'Inactive first', 'url' => 'https://first.example.com', 'sort_order' => 4, 'is_active' => false],
                    ['title' => 'Active second', 'url' => 'https://second.example.com', 'sort_order' => 8, 'is_active' => true],
                ],
            ],
        ]);
        $older->forceFill(['created_at' => now()->subMinute()])->save();

        $this->post('/admin/cards/'.$card->public_id.'/profile/revert')
            ->assertRedirect('/admin/login');
        $this->assertSame('Current', $card->fresh()->profile->display_name);

        $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/profile/revert')
            ->assertRedirect()
            ->assertSessionHas('status', 'Latest client profile edit reverted.');

        $profile = $card->fresh()->profile;
        $this->assertSame([
            'Restored Name', 'Restored headline', 'restored', 'Manila', 'Restored tagline',
            'Restored eyebrow', 'Restored welcome', "Restored\nbody", 'Restored CTA',
            'https://restored.example.com/cta', 'profile-avatars/restored.jpg', '#112233',
        ], [
            $profile->display_name, $profile->headline, $profile->username, $profile->location, $profile->tagline,
            $profile->welcome_eyebrow, $profile->welcome_heading, $profile->welcome_body, $profile->welcome_cta_label,
            $profile->welcome_cta_url, $profile->avatar_path, $profile->brand_color,
        ]);
        $links = $card->fresh()->nfcLinks()->orderBy('sort_order')->get();
        $this->assertSame(['Inactive first', 'Active second'], $links->pluck('title')->all());
        $this->assertSame([4, 8], $links->pluck('sort_order')->all());
        $this->assertSame([false, true], $links->pluck('is_active')->all());
        $this->assertNull($newest->fresh());
        $this->assertTrue($older->fresh()->exists);
        Storage::disk('public')->assertMissing('profile-avatars/current.jpg');
        Storage::disk('public')->assertExists('profile-avatars/restored.jpg');
    }

    public function test_admin_restore_can_return_to_no_profile_and_no_links(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/current.jpg', 'current');
        $card = $this->card(['display_name' => 'Current']);
        $card->profile->forceFill(['avatar_path' => 'profile-avatars/current.jpg'])->save();
        $card->nfcLinks()->create([
            'title' => 'Current',
            'url' => 'https://current.example.com',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $card->profileEdits()->create([
            'ip_address' => '203.0.113.3',
            'snapshot' => ['profile' => null, 'links' => []],
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/profile/revert')
            ->assertSessionHas('status', 'Latest client profile edit reverted.');

        $this->assertNull($card->fresh()->profile);
        $this->assertCount(0, $card->fresh()->nfcLinks);
        $this->assertCount(0, $card->fresh()->profileEdits);
        Storage::disk('public')->assertMissing('profile-avatars/current.jpg');
    }

    public function test_admin_restore_without_history_is_harmless(): void
    {
        $card = $this->card(['display_name' => 'No history']);

        $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/profile/revert')
            ->assertRedirect()
            ->assertSessionHas('status', 'There is no client profile edit to revert.');

        $this->assertSame('No history', $card->fresh()->profile->display_name);
    }

    public function test_admin_profile_panel_shows_latest_client_edit_recovery_action(): void
    {
        $card = $this->card();
        $older = $card->profileEdits()->create([
            'ip_address' => '203.0.113.10',
            'snapshot' => ['profile' => null, 'links' => []],
        ]);
        $newest = $card->profileEdits()->create([
            'ip_address' => '2001:db8::20',
            'snapshot' => ['profile' => null, 'links' => []],
        ]);
        $older->forceFill(['created_at' => now()->subMinute()])->save();

        $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertSee('Revert latest client edit')
            ->assertSee('2001:db8::20')
            ->assertSee($newest->created_at->format('Y-m-d H:i'))
            ->assertSee(route('admin.cards.profile.revert', $card->public_id), false)
            ->assertDontSee('203.0.113.10');
    }

    public function test_deleting_profile_details_removes_audit_history_and_all_retained_avatars(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/current.jpg', 'current');
        Storage::disk('public')->put('profile-avatars/historic.jpg', 'historic');
        $card = $this->card(['display_name' => 'Delete me']);
        $card->profile->forceFill(['avatar_path' => 'profile-avatars/current.jpg'])->save();
        $card->profileEdits()->create([
            'ip_address' => '203.0.113.30',
            'snapshot' => [
                'profile' => ['avatar_path' => 'profile-avatars/historic.jpg'],
                'links' => [],
            ],
        ]);

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/profile')
            ->assertSessionHas('status', 'Profile details deleted.');

        $this->assertNull($card->fresh()->profile);
        $this->assertCount(0, $card->fresh()->profileEdits);
        Storage::disk('public')->assertMissing('profile-avatars/current.jpg');
        Storage::disk('public')->assertMissing('profile-avatars/historic.jpg');
    }

    public function test_resetting_nfc_removes_profile_audit_history_and_retained_avatars(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/historic.jpg', 'historic');
        $card = $this->card(['display_name' => 'Reset me']);
        $card->profileEdits()->create([
            'ip_address' => '203.0.113.31',
            'snapshot' => [
                'profile' => ['avatar_path' => 'profile-avatars/historic.jpg'],
                'links' => [],
            ],
        ]);

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/nfc')
            ->assertSessionHas('status');

        $this->assertCount(0, $card->fresh()->profileEdits);
        Storage::disk('public')->assertMissing('profile-avatars/historic.jpg');
    }

    public function test_admin_avatar_removal_retains_a_file_referenced_by_client_history(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-avatars/retained.jpg', 'retained');
        $card = $this->card(['display_name' => 'Avatar history']);
        $card->profile->forceFill([
            'avatar_path' => 'profile-avatars/retained.jpg',
            'brand_color' => '#123456',
        ])->save();
        $card->profileEdits()->create([
            'ip_address' => '203.0.113.40',
            'snapshot' => [
                'profile' => [
                    'display_name' => 'Before',
                    'headline' => null,
                    'username' => null,
                    'location' => null,
                    'tagline' => null,
                    'welcome_eyebrow' => null,
                    'welcome_heading' => null,
                    'welcome_body' => null,
                    'welcome_cta_label' => null,
                    'welcome_cta_url' => null,
                    'avatar_path' => 'profile-avatars/retained.jpg',
                    'brand_color' => '#123456',
                ],
                'links' => [],
            ],
        ]);

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/profile/avatar')
            ->assertSessionHas('status', 'Profile avatar removed.');

        Storage::disk('public')->assertExists('profile-avatars/retained.jpg');

        $this->post('/admin/cards/'.$card->public_id.'/profile/revert')
            ->assertSessionHas('status', 'Latest client profile edit reverted.');

        $this->assertSame('profile-avatars/retained.jpg', $card->fresh()->profile->avatar_path);
        Storage::disk('public')->assertExists('profile-avatars/retained.jpg');
    }
}

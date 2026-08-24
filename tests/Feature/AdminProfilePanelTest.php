<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\NfcLink;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin card page in Profile mode.
 *
 * Nothing rendered this page in any mode-Profile state before, which is how a
 * selectable mode with no panel at all shipped green: an admin could switch a
 * live card to Profile in one click and be given no field to fill, no link
 * table and no explanation, while the public URL answered "Not set up yet"
 * indefinitely.
 */
class AdminProfilePanelTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@example.com'],
            ['name' => 'A', 'password' => Hash::make('correct-horse-1')]
        );
    }

    /** A card in Profile mode, with a profile row only if $attributes says so. */
    private function profileCard(?array $attributes = ['display_name' => 'Uriel Papa']): Card
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        if ($attributes !== null) {
            $card->profile()->save($card->profile()->make($attributes));
        }

        return $card->fresh();
    }

    private function link(Card $card, string $url = 'https://x.com/its_uriel26'): NfcLink
    {
        return $card->nfcLinks()->create([
            'title' => 'X', 'url' => $url, 'sort_order' => 0, 'is_active' => true,
        ]);
    }

    private function page(Card $card): string
    {
        return $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->getContent();
    }

    // --- The panel exists at all ---------------------------------------------

    public function test_the_profile_panel_reports_a_configured_card_as_live(): void
    {
        $body = $this->page($this->profileCard());

        $this->assertStringContainsString('Profile page live', $body);
    }

    /**
     * The status badge is the whole reason the mode is not a trap: a card with
     * no display name has to say so, because that is the one field that decides
     * whether the public URL serves a page or the not-ready notice.
     */
    public function test_the_profile_panel_names_the_missing_field_when_there_is_no_display_name(): void
    {
        $body = $this->page($this->profileCard(null));

        $this->assertStringContainsString('Needs a display name', $body);
        $this->assertStringNotContainsString('Profile page live', $body);
    }

    public function test_a_whitespace_only_display_name_does_not_read_as_live(): void
    {
        $body = $this->page($this->profileCard(['display_name' => '   ']));

        $this->assertStringContainsString('Needs a display name', $body);
        $this->assertStringNotContainsString('Profile page live', $body);
    }

    // --- The display-name field ----------------------------------------------

    public function test_the_profile_panel_offers_a_display_name_field_on_a_card_with_no_profile_row(): void
    {
        $card = $this->profileCard(null);

        $body = $this->page($card);

        $this->assertStringContainsString('name="display_name"', $body);
        $this->assertStringContainsString(route('admin.cards.profile.update', $card->public_id), $body);
    }

    public function test_the_display_name_field_is_prefilled_with_the_stored_name(): void
    {
        $body = $this->page($this->profileCard(['display_name' => 'Uriel Papa']));

        $this->assertMatchesRegularExpression(
            '/name="display_name"[^>]*value="Uriel Papa"|value="Uriel Papa"[^>]*name="display_name"/',
            $body
        );
    }

    // --- The shared link table and add-link form ------------------------------

    /**
     * The Accounts panel on the public profile page is driven by nfc_links, so
     * an admin who cannot reach the link table from Profile mode has to switch
     * the card back to Links mode to edit it - changing what the live public
     * URL serves while they work.
     */
    public function test_the_profile_panel_carries_the_link_table(): void
    {
        $card = $this->profileCard();
        $link = $this->link($card);

        $body = $this->page($card->fresh());

        $this->assertStringContainsString('https://x.com/its_uriel26', $body);
        $this->assertStringContainsString(
            route('admin.cards.nfc.update', [$card->public_id, $link->id]),
            $body,
            'each row must be editable without leaving Profile mode'
        );
        $this->assertStringContainsString(
            route('admin.cards.nfc.delete', [$card->public_id, $link->id]),
            $body,
            'each row must be deletable without leaving Profile mode'
        );
    }

    public function test_the_profile_panel_carries_the_add_link_form(): void
    {
        $card = $this->profileCard();

        $this->assertStringContainsString(
            route('admin.cards.nfc.add', $card->public_id),
            $this->page($card)
        );
    }

    public function test_the_profile_panel_can_reorder_links(): void
    {
        $card  = $this->profileCard();
        $first = $this->link($card, 'https://x.com/one');
        $card->nfcLinks()->create([
            'title' => 'GitHub', 'url' => 'https://github.com/two', 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->assertStringContainsString(
            route('admin.cards.nfc.move', [$card->public_id, $first->id]),
            $this->page($card->fresh())
        );
    }

    /**
     * The table and the add form are shared markup, so Links mode must still
     * render them after the extraction. This is the regression the extraction
     * could plausibly cause.
     */
    public function test_links_mode_still_renders_the_link_table_and_the_add_form(): void
    {
        $card = $this->card();
        $link = $this->link($card, 'https://example.com/a');

        $body = $this->page($card->fresh());

        $this->assertStringContainsString('https://example.com/a', $body);
        $this->assertStringContainsString(route('admin.cards.nfc.update', [$card->public_id, $link->id]), $body);
        $this->assertStringContainsString(route('admin.cards.nfc.add', $card->public_id), $body);
    }

    // --- The per-mode delete -------------------------------------------------

    /**
     * Asserted on the form, not on the route URL: profile.update and
     * profile.delete are the same path and differ only by verb, so the update
     * form's action already contains the delete route's URL. Only the DELETE
     * form itself distinguishes the two.
     */
    public function test_the_profile_panel_offers_a_per_mode_delete(): void
    {
        $body = $this->page($this->profileCard());

        $this->assertStringContainsString(
            'Delete profile details',
            $body,
            'clearing profile data must not require a whole-card reset, which also destroys the QR destination'
        );
        $this->assertMatchesRegularExpression(
            '#<form id="del-profile".*?_method"\s+value="DELETE"#s',
            $body,
            'the button needs a DELETE form to submit'
        );
    }

    public function test_there_is_nothing_to_delete_when_no_profile_row_exists(): void
    {
        $body = $this->page($this->profileCard(null));

        $this->assertStringNotContainsString('Delete profile details', $body);
        $this->assertStringNotContainsString('id="del-profile"', $body);
    }

    // --- The deferred fields -------------------------------------------------

    public function test_the_profile_panel_says_the_remaining_fields_arrive_later(): void
    {
        $this->assertStringContainsString(
            'arrive in a later release',
            $this->page($this->profileCard()),
            'a panel with one field and no explanation reads as broken'
        );
    }

    // --- Writing the display name --------------------------------------------

    public function test_saving_a_display_name_configures_the_card(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => 'Uriel Papa'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Profile details saved.');

        $card->refresh();

        $this->assertSame('Uriel Papa', $card->profile->display_name);
        $this->assertTrue($card->nfcModeIsConfigured());
    }

    public function test_saving_a_display_name_updates_an_existing_profile_rather_than_adding_a_second(): void
    {
        $card = $this->profileCard();

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => 'Yuriel Yel']);

        $this->assertSame(1, \App\Models\NfcProfile::query()->where('card_id', $card->id)->count());
        $this->assertSame('Yuriel Yel', $card->fresh()->profile->display_name);
    }

    /**
     * isMeaningful() trims, so a whitespace-only name is not a configured card.
     * The write path normalises rather than leaning on the model: NfcProfile's
     * SQL twin uses SQL trim(), which does not remove tabs or newlines, and its
     * docblock states the divergence is unreachable "because every write path
     * strips control characters first". This is one of those write paths.
     */
    public function test_a_whitespace_only_display_name_is_stored_as_nothing(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => "  \t  "])
            ->assertRedirect()
            ->assertSessionHas('status', 'Saved. Add a display name before this card can display a profile.');

        $card->refresh();

        $this->assertNull($card->profile->display_name);
        $this->assertFalse($card->profile->isMeaningful());
        $this->assertFalse($card->nfcModeIsConfigured());
        $this->assertFalse(
            \App\Models\NfcProfile::query()->meaningful()->where('card_id', $card->id)->exists(),
            'the SQL twin must agree: a stored tab would make it disagree with isMeaningful()'
        );
    }

    public function test_control_characters_are_stripped_from_a_display_name(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => "Uriel\r\nPapa"]);

        $this->assertSame('UrielPapa', $card->fresh()->profile->display_name);
    }

    public function test_a_display_name_longer_than_the_column_is_rejected(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->from('/admin/cards/'.$card->public_id)
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => str_repeat('a', 121)])
            ->assertSessionHasErrors('display_name');

        $this->assertNull($card->fresh()->profile);
    }

    public function test_a_display_name_at_the_column_limit_is_accepted(): void
    {
        $card = $this->profileCard(null);
        $name = str_repeat('a', 120);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => $name])
            ->assertSessionHasNoErrors();

        $this->assertSame($name, $card->fresh()->profile->display_name);
    }

    /**
     * card_id, avatar_path and brand_color are deliberately outside $fillable.
     * The panel's form is the first request-driven writer of this table, so the
     * guard is worth pinning at the endpoint rather than only on the model.
     */
    public function test_the_profile_form_cannot_write_a_non_fillable_column(): void
    {
        $card  = $this->profileCard(null);
        $other = $this->card();

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', [
                'display_name' => 'Uriel Papa',
                'card_id'      => $other->id,
                'avatar_path'  => '../../etc/passwd',
                'brand_color'  => '#ff0000',
            ]);

        $stored = $card->fresh()->profile;

        $this->assertSame($card->id, $stored->card_id);
        $this->assertNull($stored->avatar_path);
        $this->assertNull($stored->brand_color);
    }

    // --- Deleting the profile details ----------------------------------------

    public function test_deleting_the_profile_details_clears_the_row(): void
    {
        $card = $this->profileCard();

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/profile')
            ->assertRedirect()
            ->assertSessionHas('status', 'Profile details deleted.');

        $this->assertNull($card->fresh()->profile);
    }

    public function test_deleting_the_profile_details_deletes_the_avatar_file(): void
    {
        Storage::fake('public');

        $card = $this->profileCard();
        Storage::disk('public')->put('profile-avatars/old.jpg', 'not-really-a-jpeg');
        $card->profile->forceFill(['avatar_path' => 'profile-avatars/old.jpg'])->save();

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/profile');

        Storage::disk('public')->assertMissing('profile-avatars/old.jpg');
    }

    /**
     * The whole point of a per-mode delete: "Reset entire card" would take the
     * QR destination with it, and the printed QR is what a re-issued card is
     * still pointing at.
     */
    public function test_deleting_the_profile_details_leaves_the_qr_destination_and_the_links_alone(): void
    {
        $card = $this->profileCard();
        $card->forceFill(['qr_destination' => 'https://example.com/qr', 'qr_configured_at' => now()])->save();
        $this->link($card);

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/profile');

        $card->refresh();

        $this->assertSame('https://example.com/qr', $card->qr_destination);
        $this->assertSame(1, $card->nfcLinks()->count());
        $this->assertSame(NfcMode::Profile, $card->nfcMode(), 'the admin must not be thrown out of the mode');
    }

    public function test_deleting_the_profile_details_leaves_the_contact_profile_alone(): void
    {
        $card = $this->profileCard();
        $card->contactProfile()->save($card->contactProfile()->make(['first_name' => 'John']));

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/profile');

        $this->assertNotNull($card->fresh()->contactProfile, 'a per-mode delete clears one mode');
    }

    public function test_deleting_when_there_is_no_profile_row_is_harmless(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/profile')
            ->assertRedirect()
            ->assertSessionHas('status', 'Profile details deleted.');

        $this->assertNull($card->fresh()->profile);
    }

    // --- Authorisation -------------------------------------------------------

    public function test_the_profile_endpoints_require_authentication(): void
    {
        $card = $this->profileCard();

        $this->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => 'Intruder'])
            ->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/profile')
            ->assertRedirect('/admin/login');

        $this->assertSame('Uriel Papa', $card->fresh()->profile->display_name);
    }

    // --- Mode switching stays lossless ---------------------------------------

    public function test_switching_away_from_profile_mode_keeps_the_display_name(): void
    {
        $card = $this->profileCard();

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => 'links']);

        $this->assertSame('Uriel Papa', $card->fresh()->profile->display_name, 'mode switching never deletes');
    }
}

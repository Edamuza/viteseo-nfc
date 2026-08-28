<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\NfcLink;
use App\Models\NfcProfile;
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
            ->get('/admin/cards/'.$card->public_id.'?edit=1')
            ->assertOk()
            ->getContent();
    }

    /**
     * Does the page carry a form that submits to exactly this URL with exactly
     * this verb?
     *
     * Searching the page for a route() URL is not enough here, and fails in two
     * separate ways that both let an assertion pass on markup containing none
     * of what it names:
     *
     *  - Two verbs, one URL. nfc.update and nfc.delete are both
     *    /nfc-links/{link}; profile.update and profile.delete are both
     *    /profile. The edit form's action satisfies a search for the delete
     *    route, so every per-row delete form could be removed with the suite
     *    still green - which is exactly what happened.
     *  - One URL inside another. nfc.add is /nfc-links, a strict prefix of
     *    /nfc-links/{link}, itself a strict prefix of /nfc-links/{link}/move.
     *    An unanchored search for the add-link URL matches any per-row form.
     *
     * So the action is anchored by its own closing quote, and the verb is read
     * from the form's _method field. The body between them is tempered against
     * </form> so the match cannot run past this form and borrow the verb of a
     * later one - the page always has a later DELETE form to borrow.
     *
     * @param  string|null  $method  null for a plain POST form, which has no _method field
     */
    private function assertHasForm(string $body, string $action, ?string $method, string $because): void
    {
        $pattern = '<form[^>]*action="'.preg_quote($action, '#').'"';

        if ($method !== null) {
            $pattern .= '(?:(?!</form>).)*?name="_method"\s+value="'.$method.'"';
        }

        $this->assertMatchesRegularExpression('#'.$pattern.'#s', $body, $because);
    }

    /**
     * The confirm() text of the form that submits to exactly this URL.
     *
     * Read out of the form's own onsubmit rather than searched for across the
     * page, so an assertion cannot be satisfied by unrelated prose elsewhere.
     */
    private function confirmTextFor(Card $card, string $action): string
    {
        $pattern = '#<form[^>]*action="'.preg_quote($action, '#').'"[^>]*onsubmit="return confirm\((.*?)\)"#s';

        $this->assertMatchesRegularExpression($pattern, $this->page($card), 'no confirmed form posts to '.$action);
        preg_match($pattern, $this->page($card), $m);

        return $m[1];
    }

    private function assertHasNoForm(string $body, string $action, ?string $method, string $because): void
    {
        $pattern = '<form[^>]*action="'.preg_quote($action, '#').'"';

        if ($method !== null) {
            $pattern .= '(?:(?!</form>).)*?name="_method"\s+value="'.$method.'"';
        }

        $this->assertDoesNotMatchRegularExpression('#'.$pattern.'#s', $body, $because);
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
        $this->assertHasForm(
            $body,
            route('admin.cards.profile.update', $card->public_id),
            'PATCH',
            'the field needs a PATCH form to submit to'
        );
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
     * an admin who cannot reach the link rows from Profile mode has to switch
     * the card back to Links mode to edit them - changing what the live public
     * URL serves while they work.
     */
    public function test_the_profile_panel_carries_the_link_rows(): void
    {
        $card = $this->profileCard();
        $this->link($card);

        $body = $this->page($card->fresh());

        $this->assertStringContainsString('https://x.com/its_uriel26', $body);
        $this->assertStringContainsString('name="links[0][title]"', $body);
        $this->assertStringContainsString('name="links[0][url]"', $body);
    }

    /**
     * A profile page and its links are one thing to the person editing them,
     * and were two saves only because they are two tables. The rows live
     * inside the profile form, so one Save writes both.
     */
    public function test_the_profile_and_its_links_share_one_save(): void
    {
        $card = $this->profileCard();
        $this->link($card);

        $body = $this->page($card->fresh());
        $form = strpos($body, route('admin.cards.profile.update', $card->public_id));

        $this->assertIsInt($form);
        $this->assertGreaterThan($form, strpos($body, 'name="links[0][title]"'), 'the rows are inside the profile form');
        $this->assertLessThan(
            strpos($body, '</form>', (int) strpos($body, 'name="links[0][title]"')),
            strpos($body, 'name="links[0][title]"'),
        );
        $this->assertSame(1, substr_count($body, '>Save changes<'), 'one Save, not one per table');
    }

    public function test_the_profile_panel_can_gain_a_first_link(): void
    {
        $card = $this->profileCard();

        $body = $this->page($card);

        // The blank row is added from this template, so a Profile card can
        // gain its first link without a mode switch.
        $this->assertStringContainsString('id="lnk-new"', $body);
        $this->assertStringContainsString('id="lnk-add"', $body);
    }

    /**
     * Order decides the order of the public page's Accounts panel. There is no
     * move endpoint any more: the rows are saved in the order they arrive, so
     * dragging one is a DOM move that the next Save writes down.
     */
    public function test_saving_the_rows_in_a_new_order_reorders_the_public_panel(): void
    {
        $card = $this->profileCard();
        $card->nfcLinks()->create(['title' => 'One', 'url' => 'https://example.com/one', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Two', 'url' => 'https://example.com/two', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', [
                'display_name' => 'Uriel Papa',
                'links' => [
                    ['title' => 'Two', 'url' => 'https://example.com/two', 'is_active' => '1'],
                    ['title' => 'One', 'url' => 'https://example.com/one', 'is_active' => '1'],
                ],
            ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['Two', 'One'],
            $card->fresh()->nfcLinks()->orderBy('sort_order')->pluck('title')->all()
        );
    }

    /**
     * The rows are shared markup, so Links mode must still render them - and
     * there it has a Save of its own, because there is no profile form to
     * carry it.
     */
    public function test_links_mode_still_renders_the_rows_and_its_own_save(): void
    {
        $card = $this->card();
        $this->link($card, 'https://example.com/a');

        $body = $this->page($card->fresh());

        $this->assertStringContainsString('https://example.com/a', $body);
        $this->assertStringContainsString('name="links[0][title]"', $body);
        $this->assertStringContainsString(route('admin.cards.nfc.links.save', $card->public_id), $body);
        $this->assertSame(1, substr_count($body, '>Save changes<'));
    }

    // --- The per-mode delete -------------------------------------------------

    /**
     * Asserted on the form's own verb, not on the route URL: profile.update and
     * profile.delete are the same path and differ only by verb, so the update
     * form's action already contains the delete route's URL.
     */
    public function test_the_profile_panel_offers_a_per_mode_delete(): void
    {
        $card = $this->profileCard();
        $body = $this->page($card);

        $this->assertStringContainsString(
            'Delete profile details',
            $body,
            'clearing profile data must not require a whole-card reset, which also destroys the QR destination'
        );
        $this->assertHasForm(
            $body,
            route('admin.cards.profile.delete', $card->public_id),
            'DELETE',
            'the button needs a DELETE form to submit'
        );
    }

    /**
     * deleteProfile() deletes the whole nfc_profiles row, so the dialog has to
     * describe the row. Enumerating the fields it happens to hold today - "display
     * name, profile avatar" - is a promise scheduled to become false: increment 5
     * adds the writers for headline, username, location, tagline, welcome_* and
     * brand_color, and each one silently makes the list shorter than the truth.
     * That is precisely how the two reset dialogs went stale.
     *
     * Driven from $fillable so a new field cannot be added without either the
     * dialog staying scope-shaped or this test objecting.
     */
    public function test_the_delete_dialog_describes_the_row_rather_than_listing_its_fields(): void
    {
        $card    = $this->profileCard();
        $confirm = $this->confirmTextFor($card, route('admin.cards.profile.delete', $card->public_id));

        foreach ((new NfcProfile)->getFillable() as $column) {
            $this->assertStringNotContainsStringIgnoringCase(
                str_replace('_', ' ', $column),
                $confirm,
                "the dialog enumerates $column, so it will under-state the delete as soon as another field is added"
            );
        }
    }

    public function test_the_delete_dialog_says_the_links_and_the_qr_survive(): void
    {
        $card    = $this->profileCard();
        $confirm = $this->confirmTextFor($card, route('admin.cards.profile.delete', $card->public_id));

        $this->assertStringContainsString('KEPT', $confirm);
        $this->assertStringContainsString('QR', $confirm, 'the per-mode delete exists precisely because it spares the QR');
        $this->assertStringContainsStringIgnoringCase('links', $confirm);
    }

    public function test_there_is_nothing_to_delete_when_no_profile_row_exists(): void
    {
        $card = $this->profileCard(null);
        $body = $this->page($card);

        $this->assertStringNotContainsString('Delete profile details', $body);
        $this->assertHasNoForm(
            $body,
            route('admin.cards.profile.delete', $card->public_id),
            'DELETE',
            'the update form shares this URL, so only its verb tells the two apart'
        );
    }

    // --- Full Profile editor -------------------------------------------------

    public function test_the_profile_panel_offers_every_identity_welcome_and_avatar_field(): void
    {
        $card = $this->profileCard();
        $body = $this->page($card);

        foreach ([
            'display_name', 'headline', 'username', 'location', 'tagline', 'avatar',
            'welcome_eyebrow', 'welcome_heading', 'welcome_body',
            'welcome_cta_label', 'welcome_cta_url',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $body, "missing {$field}");
        }

        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('href="'.route('nfc.show', $card->public_id).'"', $body);
        $this->assertStringNotContainsString('arrive in a later release', $body);
    }

    public function test_the_card_header_uses_the_current_mode_instead_of_link_count(): void
    {
        $body = $this->page($this->profileCard());

        $this->assertStringContainsString('NFC&nbsp;&middot;&nbsp;Profile page', $body);
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

    /**
     * The natural way an admin backs a card out of Profile mode without losing
     * the row: clear the field and save. The row has to survive - deleting it is
     * what the separate Delete button is for, and doing it here would take the
     * avatar and every other field with it - while the badge, the model, the SQL
     * twin and the public page all have to agree the card is no longer live.
     *
     * The whitespace test above only ever exercised a card that had no row to
     * begin with, so nothing covered the transition from live to blank.
     */
    public function test_blanking_the_display_name_on_a_live_card_stands_the_page_down(): void
    {
        $card = $this->profileCard(['display_name' => 'Uriel Papa']);

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('Uriel Papa');
        $this->assertTrue($card->nfcModeIsConfigured(), 'the fixture starts live');

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => ''])
            ->assertRedirect()
            ->assertSessionHas('status', 'Saved. Add a display name before this card can display a profile.');

        $card->refresh();

        $this->assertNotNull($card->profile, 'clearing the field must not delete the row');
        $this->assertNull($card->profile->display_name);
        $this->assertFalse($card->profile->isMeaningful());
        $this->assertFalse($card->nfcModeIsConfigured());
        $this->assertFalse(
            NfcProfile::query()->meaningful()->where('card_id', $card->id)->exists(),
            'the SQL twin must agree the card is no longer configured'
        );

        $this->assertStringContainsString('Needs a display name', $this->page($card));

        $public = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $this->assertStringNotContainsString('Uriel Papa', $public, 'the public page still serves the name it was told to drop');
    }

    /**
     * "0" is falsy in PHP, so `stripControl(...) ?: null` stored a display name
     * of 0 as NULL: the save reported success, the badge said the card still
     * needed a name, and the public URL served the not-ready page. A one-glyph
     * name is unusual but it is not empty, and the admin has no way to tell the
     * difference between "rejected" and "quietly discarded".
     */
    public function test_a_display_name_of_zero_is_stored_rather_than_dropped(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => '0'])
            ->assertSessionHas('status', 'Profile details saved.');

        $card->refresh();

        $this->assertSame('0', $card->profile->display_name);
        $this->assertTrue($card->profile->isMeaningful());
        $this->assertTrue($card->nfcModeIsConfigured());
        $this->assertTrue(
            NfcProfile::query()->meaningful()->where('card_id', $card->id)->exists(),
            'the SQL twin must agree that "0" is a name'
        );
    }

    /**
     * A /u pattern returns null on malformed UTF-8, and `?? ''` turned that into
     * the whole name being discarded. Refusing the bad bytes is load-bearing -
     * they reach a utf8mb4 column and MySQL would reject the write - but losing
     * the valid characters with them is a bigger loss than the problem.
     */
    public function test_malformed_utf8_in_a_display_name_costs_only_the_bad_bytes(): void
    {
        $card = $this->profileCard(null);

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/profile', ['display_name' => "Uriel\xFF Papa"])
            ->assertSessionHas('status', 'Profile details saved.');

        $stored = $card->fresh()->profile->display_name;

        $this->assertNotNull($stored, 'the whole name was discarded over one bad byte');
        $this->assertStringContainsString('Uriel', $stored);
        $this->assertStringContainsString('Papa', $stored);
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'), 'what is stored must be valid UTF-8');
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

    // --- The whole journey ---------------------------------------------------

    /**
     * The journey finding 1 said was impossible, in one test: switch a card to
     * Profile, name it, add a link, see it published, clear it again - all from
     * inside Profile mode and without disturbing the QR destination the printed
     * card points at.
     *
     * Every individual claim here has its own test above. This one exists
     * because the failure was a gap *between* the pieces: each part worked and
     * nothing joined them up, so the mode was reachable and unusable.
     */
    public function test_an_admin_can_configure_and_clear_a_profile_card_without_leaving_the_mode(): void
    {
        $admin = $this->admin();
        $card  = $this->card();

        // A live QR destination, to prove the whole journey never touches it.
        $this->actingAs($admin)->patch('/admin/cards/'.$card->public_id.'/qr', [
            'destination' => 'https://viteseo.ph/landing',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => 'profile'])
            ->assertSessionHasNoErrors();

        // A card in Profile mode with nothing in it says so, and offers the one
        // field that fixes it.
        $body = $this->page($card->fresh());
        $this->assertStringContainsString('Needs a display name', $body);
        $this->assertStringContainsString('name="display_name"', $body);
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('What should your card do?');

        // Name and link in ONE save, which is the whole point of the merged
        // form: two saves for one page was the complaint that produced it.
        $this->actingAs($admin)->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Uriel Papa',
            'links' => [['title' => 'X', 'url' => 'https://x.com/its_uriel26', 'is_active' => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(NfcMode::Profile, $card->fresh()->nfcMode(), 'the admin never left the mode');
        $this->assertStringContainsString('Profile page live', $this->page($card->fresh()));

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Uriel Papa')
            ->assertSee('Accounts')
            ->assertSee('x.com/its_uriel26');

        $this->actingAs($admin)->delete('/admin/cards/'.$card->public_id.'/profile');

        $card->refresh();
        $this->assertNull($card->profile);
        $this->assertSame(NfcMode::Profile, $card->nfcMode());
        $this->assertSame('https://viteseo.ph/landing', $card->qr_destination, 'the printed QR still points somewhere');
        $this->assertSame(1, $card->nfcLinks()->count(), 'a per-mode delete clears one mode');

        $end = $this->get('/n/'.$card->public_id)->assertOk();
        $end->assertSee('What should your card do?');
        $end->assertDontSee('Uriel Papa');
        // The chooser lists card types but never renders a PIN field itself.
        $end->assertDontSee('activation_pin', false);

        $this->get('/q/'.$card->public_id)->assertRedirect('https://viteseo.ph/landing');
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

<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use App\Services\CardResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CardResetTest extends TestCase
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

    /**
     * A card carrying data for every mode at once, plus branding, a QR
     * destination, a stored contact photo, a profile row and its avatar.
     * Mode switching is lossless by design, so this is a realistic state
     * for a re-issued card.
     */
    private function fullyPopulatedCard(): Card
    {
        $card = $this->card();

        $card->nfcLinks()->create([
            'title' => 'Site', 'url' => 'https://example.com/s', 'sort_order' => 0, 'is_active' => true,
        ]);

        Storage::disk('public')->put('contact-photos/old.jpg', 'not-really-a-jpeg');
        Storage::disk('public')->put('logos/acme.png', 'not-really-a-png');

        $profile = $card->contactProfile()->make([
            'first_name' => 'John', 'last_name' => 'Doe', 'company' => 'Acme Ltd',
        ]);
        $profile->photo_path = 'contact-photos/old.jpg';
        $card->contactProfile()->save($profile);

        $card->forceFill([
            'nfc_mode'          => 'contact',
            'nfc_phone_number'  => '+639171234567',
            'nfc_email_address' => 'hi@example.com',
            'nfc_email_subject' => 'Hello',
            'nfc_email_body'    => 'Body',
            'nfc_page_title'    => 'Acme',
            'nfc_page_subtitle' => 'Links',
            'nfc_logo_path'     => 'logos/acme.png',
            'nfc_configured_at' => '2024-06-15 09:30:00',
            'qr_destination'    => 'https://example.com/qr',
            'qr_configured_at'  => '2024-06-15 09:30:00',
        ])->save();

        Storage::disk('public')->put('profile-avatars/old.jpg', 'not-really-a-jpeg');

        $profile = $card->profile()->make(['display_name' => 'John Doe', 'headline' => 'CEO, Acme Ltd']);
        $profile->avatar_path = 'profile-avatars/old.jpg';
        $card->profile()->save($profile);

        return $card->fresh();
    }

    private function assertNfcSideIsClear(Card $card): void
    {
        $card->refresh();

        $this->assertSame(0, $card->nfcLinks()->count(), 'links');
        $this->assertNull($card->contactProfile, 'contact profile');
        $this->assertNull($card->profile, 'profile');
        $this->assertNull($card->nfc_phone_number, 'phone');
        $this->assertNull($card->nfc_email_address, 'email address');
        $this->assertNull($card->nfc_email_subject, 'email subject');
        $this->assertNull($card->nfc_email_body, 'email body');
        $this->assertNull($card->nfc_page_title, 'page title');
        $this->assertNull($card->nfc_page_subtitle, 'page subtitle');
        $this->assertNull($card->nfc_logo_path, 'logo path');
        $this->assertNull($card->nfc_configured_at, 'configured timestamp');
        $this->assertSame(NfcMode::Links, $card->nfcMode(), 'mode returns to links');
    }

    public function test_resetting_the_nfc_side_clears_every_modes_payload(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        app(CardResetService::class)->resetNfcSide($card);

        $this->assertNfcSideIsClear($card);
    }

    public function test_resetting_the_nfc_side_deletes_the_contact_photo_file(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('contact-photos/old.jpg');

        app(CardResetService::class)->resetNfcSide($card);

        Storage::disk('public')->assertMissing('contact-photos/old.jpg');
    }

    public function test_resetting_the_nfc_side_deletes_the_profile_avatar_file(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('profile-avatars/old.jpg');

        app(CardResetService::class)->resetNfcSide($card);

        Storage::disk('public')->assertMissing('profile-avatars/old.jpg');
    }

    /**
     * The landing page logo is the third publicly served image, and it was the
     * only one whose column was nulled with no matching forget(). That leaves
     * the file at its guessable /storage/... URL after a reset that reported
     * success - the exact leak PublicImageStorage exists to close, and the one
     * the contact photo and the profile avatar are already protected from.
     *
     * Increment 1 recorded a constraint saying not to delete a file for this
     * column because no upload path writes it. That reasoning does not survive
     * its own precedent: no upload path wrote profile avatars either when the
     * avatar's forget() was added and tested this same way. A column the reset
     * bothers to null is a column something is expected to write, and the
     * cheapest moment to make the file behave like the other two is before,
     * not after, the writer exists.
     */
    public function test_resetting_the_nfc_side_deletes_the_landing_page_logo_file(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('logos/acme.png');

        app(CardResetService::class)->resetNfcSide($card);

        Storage::disk('public')->assertMissing('logos/acme.png');
    }

    public function test_resetting_everything_deletes_the_landing_page_logo_file(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        app(CardResetService::class)->resetEverything($card);

        Storage::disk('public')->assertMissing('logos/acme.png');
    }

    /**
     * Every publicly served image the reset nulls a column for is removed from
     * disk. Written as one list so a fourth image cannot be added with a column
     * and no file deletion, which is how the logo came to be the odd one out.
     */
    public function test_the_reset_leaves_no_publicly_served_image_behind(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        app(CardResetService::class)->resetEverything($card);

        foreach (['contact-photos/old.jpg', 'profile-avatars/old.jpg', 'logos/acme.png'] as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_resetting_the_nfc_side_clears_the_profile_row(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->assertNotNull($card->profile, 'the fixture has a profile to clear');

        app(CardResetService::class)->resetNfcSide($card);

        $this->assertNull($card->fresh()->profile);
    }

    public function test_resetting_the_nfc_side_leaves_the_qr_alone(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $originalQrConfiguredAt = $card->qr_configured_at->format('Y-m-d H:i:s');

        app(CardResetService::class)->resetNfcSide($card);
        $card->refresh();

        $this->assertSame('https://example.com/qr', $card->qr_destination);
        $this->assertSame($originalQrConfiguredAt, $card->qr_configured_at->format('Y-m-d H:i:s'));
    }

    public function test_resetting_the_nfc_side_keeps_the_permanent_identity(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $number = $card->card_number;
        $publicId = $card->public_id;
        $nfcUrl = $card->nfcUrl();
        $qrUrl = $card->qrUrl();

        app(CardResetService::class)->resetNfcSide($card);
        $card->refresh();

        $this->assertSame($number, $card->card_number);
        $this->assertSame($publicId, $card->public_id);
        $this->assertSame($nfcUrl, $card->nfcUrl());
        $this->assertSame($qrUrl, $card->qrUrl());
    }

    public function test_contact_photo_survives_when_the_reset_transaction_rolls_back(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('contact-photos/old.jpg');

        $listener = function () {
            throw new \RuntimeException('forced rollback');
        };

        Card::saving($listener);

        try {
            app(CardResetService::class)->resetNfcSide($card);
            $this->fail('Expected reset to abort when the card save fails.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced rollback', $e->getMessage());
        } finally {
            // Blanket forget is safe only because this app registers no other
            // Card saving listeners; re-check if that ever changes.
            Card::getEventDispatcher()?->forget('eloquent.saving: '.Card::class);
        }

        Storage::disk('public')->assertExists('contact-photos/old.jpg');
        $card->refresh();
        $this->assertNotNull($card->contactProfile);
        $this->assertSame('contact-photos/old.jpg', $card->contactProfile->photo_path);
    }

    public function test_contact_photo_survives_when_reset_everything_transaction_rolls_back(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('contact-photos/old.jpg');

        $listener = function () {
            throw new \RuntimeException('forced rollback');
        };

        Card::saving($listener);

        try {
            app(CardResetService::class)->resetEverything($card);
            $this->fail('Expected reset to abort when the card save fails.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced rollback', $e->getMessage());
        } finally {
            // Blanket forget is safe only because this app registers no other
            // Card saving listeners; re-check if that ever changes.
            Card::getEventDispatcher()?->forget('eloquent.saving: '.Card::class);
        }

        Storage::disk('public')->assertExists('contact-photos/old.jpg');
        $card->refresh();
        $this->assertNotNull($card->contactProfile);
        $this->assertSame('contact-photos/old.jpg', $card->contactProfile->photo_path);
    }

    public function test_resetting_everything_clears_the_nfc_side_and_the_qr(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        app(CardResetService::class)->resetEverything($card);

        $this->assertNfcSideIsClear($card);
        $this->assertNull($card->qr_destination, 'qr destination');
        $this->assertNull($card->qr_configured_at, 'qr timestamp');
    }

    public function test_resetting_everything_keeps_the_permanent_identity(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $publicId = $card->public_id;
        $nfcUrl = $card->nfcUrl();
        $qrUrl = $card->qrUrl();

        app(CardResetService::class)->resetEverything($card);
        $card->refresh();

        $this->assertSame($publicId, $card->public_id);
        $this->assertSame($nfcUrl, $card->nfcUrl());
        $this->assertSame($qrUrl, $card->qrUrl());
    }

    public function test_admin_nfc_reset_endpoint_clears_every_mode(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/nfc')
            ->assertRedirect()
            // The message is the promise the admin acts on when re-issuing a
            // card, so it is asserted here: it must not drift ahead of what
            // the service clears.
            ->assertSessionHas('status', 'NFC reset. Every mode has been cleared and the next tap will show the setup page.');

        $this->assertNfcSideIsClear($card);
        $this->assertSame('https://example.com/qr', $card->qr_destination, 'QR is not part of an NFC reset');
        Storage::disk('public')->assertMissing('contact-photos/old.jpg');
    }

    public function test_admin_full_reset_endpoint_clears_the_qr_too(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $pinHash = $card->activation_pin_hash;

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/reset')
            ->assertRedirect()
            ->assertSessionHas('status', 'Card reset. Both sides are blank. Card number, public ID, both permanent URLs and the activation PIN are unchanged.');

        $this->assertNfcSideIsClear($card);
        $this->assertNull($card->qr_destination);
        $this->assertNull($card->qr_configured_at);

        // The message names the PIN as surviving, so the claim is asserted
        // rather than left to the reader of the docblock.
        $this->assertSame($pinHash, $card->activation_pin_hash, 'activation PIN survives a full reset');
    }

    public function test_reset_endpoints_require_authentication(): void
    {
        $card = $this->card();

        // Guests land on /admin/login, not Laravel's default /login: there is
        // no /login route in this app. AdminTest asserts the same target.
        $this->delete('/admin/cards/'.$card->public_id.'/nfc')->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/reset')->assertRedirect('/admin/login');
    }

    /**
     * The next tests assert the reset through the public routes rather than the
     * columns: those are what a visitor to a re-issued card actually reaches.
     * Each asserts the live "before" state first, so a reset that silently
     * stopped working cannot pass by never having been configured, and every
     * value asserted as gone afterwards is one the page demonstrably printed
     * beforehand - an assertion against a value the view never renders would
     * be an assertion that can never fail.
     *
     * Each mode has its own test, and each puts the card back into that mode
     * after the reset. That is the real re-issue flow - reset, then configure
     * for the new holder - and it is what makes these tests bite: a reset that
     * only flipped nfc_mode back to Links while leaving the data behind would
     * pass if the page were checked in Links mode, then leak the moment the
     * mode was selected again.
     */
    /** Mode selection through the admin endpoint, as an admin re-issuing the card would. */
    private function selectNfcMode(Card $card, string $mode): void
    {
        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => $mode])
            ->assertSessionHasNoErrors();
    }

    public function test_a_reset_card_no_longer_serves_the_previous_holders_contact_page(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        // The contact view renders the profile's own phone and email columns,
        // never the card's nfc_* ones, so the profile carries them here.
        $card->contactProfile->fill([
            'mobile_phone'   => '+639171112222',
            'personal_email' => 'john@example.com',
        ])->save();

        // Before: every value asserted below is on the live page.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('John Doe')
            ->assertSee('Acme Ltd')
            ->assertSee('+639171112222')
            ->assertSee('john@example.com');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');
        $this->selectNfcMode($card, 'contact');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        foreach (['John Doe', 'Acme Ltd', '+639171112222', 'john@example.com'] as $leaked) {
            $this->assertStringNotContainsString($leaked, $body, $leaked.' survived the reset');
        }
    }

    public function test_a_reset_card_no_longer_serves_the_previous_holders_phone_number(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => 'phone'])->save();

        // Phone mode prints nfc_phone_number verbatim as the heading.
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('+639171234567');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');
        $this->selectNfcMode($card, 'phone');

        $this->get('/n/'.$card->public_id)->assertOk()->assertDontSee('+639171234567');
    }

    public function test_a_reset_card_no_longer_serves_the_previous_holders_email_address(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => 'email'])->save();

        // Email mode prints nfc_email_address as the heading and the subject
        // below it; the mailto href carries the same address percent-encoded.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('hi@example.com')
            ->assertSee('Hello');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');
        $this->selectNfcMode($card, 'email');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('hi@example.com', $body, 'address survived the reset');
        $this->assertStringNotContainsString('hi%40example.com', $body, 'encoded address survived in the mailto');
    }

    public function test_a_reset_card_no_longer_returns_the_previous_holders_vcard(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        // The route is /n/{card}/contact.vcf, named nfc.vcard. NfcController
        // aborts 404 unless the card is in Contact mode with a named profile,
        // so a reset card must stop returning a file at all.
        $this->get('/n/'.$card->public_id.'/contact.vcf')->assertOk();

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');

        $this->get('/n/'.$card->public_id.'/contact.vcf')->assertNotFound();
    }

    public function test_a_reset_card_stops_redirecting_to_the_previous_qr_destination(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->get('/q/'.$card->public_id)->assertRedirect('https://example.com/qr');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');

        // An unconfigured QR renders the public.qr-setup view with a 200; it
        // does not 404. See QrController::show.
        $this->get('/q/'.$card->public_id)
            ->assertOk()
            ->assertDontSee('https://example.com/qr');
    }

    /**
     * The confirmation dialogs, held to the same standard as the flash
     * messages above.
     *
     * The flash message is asserted word for word because it is a promise the
     * admin acts on when re-issuing a card. The confirm dialog is the one they
     * read *first*, and it is the last chance to stop - so a dialog that
     * under-states what is about to be destroyed is worse than a stale flash
     * message, not better. Both drifted behind the service: it grew the contact
     * profile and photo, then the profile row and avatar, while the dialogs
     * still listed only links and landing-page details.
     *
     * The confirm text is pulled out of the form's own onsubmit attribute
     * rather than searched for in the whole page, because "QR destination"
     * appears three times elsewhere on that page and would make the assertion
     * pass without the dialog saying anything at all.
     */
    private function confirmTextFor(Card $card, string $action): string
    {
        $body = $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->getContent();

        $pattern = '#<form[^>]*action="'.preg_quote($action, '#').'"[^>]*onsubmit="return confirm\((.*?)\)"#s';

        $this->assertMatchesRegularExpression($pattern, $body, 'no confirmed form posts to '.$action);
        preg_match($pattern, $body, $m);

        return $m[1];
    }

    /** @return array<string, array{string}> */
    public static function everythingAWholeCardResetDestroys(): array
    {
        return [
            'the QR destination'    => ['QR destination'],
            'every NFC link'        => ['all NFC links'],
            'the landing page'      => ['landing page details'],
            'the contact card'      => ['contact card and photo'],
            'the profile page'      => ['profile page and avatar'],
            'the saved phone'       => ['phone number'],
            'the saved email'       => ['email action'],
            'the mode it returns to' => ['mode returns to Links'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everythingAWholeCardResetDestroys')]
    public function test_the_whole_card_reset_dialog_names_what_it_destroys(string $promise): void
    {
        $card    = $this->fullyPopulatedCard();
        $confirm = $this->confirmTextFor($card, route('admin.cards.reset', $card->public_id));

        $this->assertStringContainsString(
            $promise,
            $confirm,
            'the dialog does not warn that this is destroyed, but resetEverything() destroys it'
        );
    }

    /** @return array<string, array{string}> */
    public static function everythingAnNfcResetDestroys(): array
    {
        return [
            'every NFC link'   => ['all links'],
            'the landing page' => ['landing page details'],
            'the contact card' => ['contact card and photo'],
            'the profile page' => ['profile page and avatar'],
            'the saved phone'  => ['phone number'],
            'the saved email'  => ['email action'],
        ];
    }

    /**
     * resetNfcSide() clears every mode - its own flash message says so. The
     * dialog claimed the opposite: that "any saved contact/phone/email data are
     * NOT affected". An admin who read that and pressed the button lost the
     * contact card they had been promised would survive.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everythingAnNfcResetDestroys')]
    public function test_the_nfc_reset_dialog_names_what_it_destroys(string $promise): void
    {
        // No mode is forced: the button is card-wide, so it is reachable in
        // every mode. The fixture is a Contact-mode card.
        $card = $this->fullyPopulatedCard();

        $confirm = $this->confirmTextFor($card, route('admin.cards.nfc.reset', $card->public_id));

        $this->assertStringContainsString(
            $promise,
            $confirm,
            'the dialog does not warn that this is destroyed, but resetNfcSide() destroys it'
        );
    }

    public function test_the_nfc_reset_dialog_promises_only_the_qr_survives(): void
    {
        // No mode is forced: the button is card-wide, so it is reachable in
        // every mode. The fixture is a Contact-mode card.
        $card = $this->fullyPopulatedCard();

        $confirm = $this->confirmTextFor($card, route('admin.cards.nfc.reset', $card->public_id));

        $this->assertStringNotContainsString(
            'contact/phone/email data are NOT affected',
            $confirm,
            'the dialog promised data survives that the service deletes'
        );
        $this->assertStringContainsString('QR destination', $confirm, 'the QR really does survive, and that is worth saying');
    }

    public function test_the_danger_zone_description_names_what_the_reset_clears(): void
    {
        $card = $this->fullyPopulatedCard();

        $body = $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->getContent();

        // The paragraph above the button, which is read before the dialog.
        $this->assertStringContainsString('contact card and its photo', $body);
        $this->assertStringContainsString('profile page and its avatar', $body);
    }

    // --- Where the Reset NFC button lives ------------------------------------

    /**
     * Reset NFC used to sit in the shared link editor's "Add link" row, gated on
     * the card having at least one link. Two consequences, both of them the
     * gate's fault as much as the placement's:
     *
     * A Profile card with a finished profile and no links had no way to clear
     * the NFC side at all, short of "Reset entire card" - which also destroys
     * the QR destination the printed card still points at. And adding a single
     * link made a control appear, in the Add link row, that deletes the profile
     * and returns the card to Links mode.
     *
     * The control does not depend on links, so it is not gated on links.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyNfcMode')]
    public function test_reset_nfc_is_offered_on_a_card_with_no_links(string $mode): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => $mode])->save();

        $this->assertSame(0, $card->nfcLinks()->count(), 'the fixture must have no links');

        $confirm = $this->confirmTextFor($card, route('admin.cards.nfc.reset', $card->public_id));

        $this->assertStringContainsString('ENTIRE NFC side', $confirm);
    }

    /** @return array<string, array{string}> */
    public static function everyNfcMode(): array
    {
        return collect(NfcMode::cases())
            ->mapWithKeys(fn (NfcMode $m) => [$m->value => [$m->value]])
            ->all();
    }

    /**
     * An action that deletes the contact card, the profile and every link, in
     * every mode, does not belong in the row where an admin adds one link. The
     * Danger zone is where the card's other card-wide destructive action already
     * lives, and its heading is the warning.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyNfcMode')]
    public function test_reset_nfc_lives_in_the_danger_zone(string $mode): void
    {
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => $mode])->save();

        $body = $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->getContent();

        // Both needles are anchored, having each borrowed something on the
        // first attempt: a stylesheet comment reading "/* Danger zone: ... */"
        // sits 11kB above the heading, and the reset URL /nfc is a prefix of
        // the mode form's /nfc-mode, which is the earliest match on the page.
        $dangerZone = strpos($body, '<h2>Danger zone</h2>');
        $resetNfc   = strpos($body, 'action="'.route('admin.cards.nfc.reset', $card->public_id).'"');

        $this->assertNotFalse($dangerZone, 'the page has a Danger zone');
        $this->assertNotFalse($resetNfc, 'the page offers Reset NFC');
        $this->assertGreaterThan(
            $dangerZone,
            $resetNfc,
            'Reset NFC appears before the Danger zone heading, so it is somewhere it does not belong'
        );
    }

    /**
     * The shared link editor is the table and the add form, nothing else. This
     * is what stops the button drifting back in: the partial is included by two
     * modes, so anything card-wide inside it is card-wide destruction shown
     * twice, in a place scoped to links.
     */
    public function test_the_shared_link_editor_holds_nothing_card_wide(): void
    {
        $partial = file_get_contents(resource_path('views/admin/partials/nfc-links.blade.php'));

        $this->assertStringContainsString('admin.cards.nfc.add', $partial, 'the add form is the point of it');
        $this->assertStringNotContainsString('admin.cards.nfc.reset', $partial, 'card-wide reset is not link editing');
        $this->assertStringNotContainsString('admin.cards.reset', $partial, 'card-wide reset is not link editing');
        $this->assertStringNotContainsString('admin.cards.profile.delete', $partial);
        $this->assertStringNotContainsString('admin.cards.contact.delete', $partial);
    }

    /**
     * The state the increment-2 walkthrough never visited: the admin presses the
     * door next to the safe one. Reset NFC from Profile mode is card-wide, so it
     * takes the profile with it and hands the card back in Links mode - and the
     * public URL must stop serving the profile.
     */
    public function test_reset_nfc_from_profile_mode_clears_the_profile_and_returns_to_links(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('John Doe');

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/nfc')
            ->assertRedirect();

        $card->refresh();

        $this->assertNull($card->profile, 'the profile row survived a card-wide NFC reset');
        $this->assertSame(NfcMode::Links, $card->nfcMode(), 'the mode did not return to Links');
        Storage::disk('public')->assertMissing('profile-avatars/old.jpg');

        $this->assertStringNotContainsString(
            'John Doe',
            $this->get('/n/'.$card->public_id)->assertOk()->getContent()
        );
    }

    public function test_reset_nfc_from_profile_mode_leaves_the_qr_destination_alone(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/nfc');

        $this->assertSame('https://example.com/qr', $card->fresh()->qr_destination);
    }

    public function test_a_reset_card_no_longer_serves_the_previous_holders_profile_page(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        // The "before" half is what makes the "after" half meaningful: the page
        // demonstrably served this name a moment ago.
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('John Doe');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');
        $this->selectNfcMode($card, 'profile');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('John Doe', $body, 'name survived the reset');
        $this->assertStringNotContainsString('CEO, Acme Ltd', $body, 'headline survived the reset');
    }
}

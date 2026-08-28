<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\NfcContactProfile;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NfcModesTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    private function admin(): User
    {
        // firstOrCreate: several tests call this more than once.
        return User::firstOrCreate(
            ['email' => 'a@example.com'],
            ['name' => 'A', 'password' => Hash::make('correct-horse-1')]
        );
    }

    private function profileFor(Card $card, array $attrs = []): NfcContactProfile
    {
        $p = $card->contactProfile()->make(array_merge([
            'first_name' => 'John', 'last_name' => 'Doe',
        ], $attrs));
        $card->contactProfile()->save($p);
        $card->refresh();

        return $p;
    }

    // ── Default / backward compatibility ────────────────────────────────────

    public function test_new_cards_default_to_links_mode(): void
    {
        $this->assertSame(NfcMode::Links, $this->card()->nfcMode());
    }

    public function test_links_mode_zero_links_still_shows_setup(): void
    {
        $this->get('/n/'.$this->card()->public_id)->assertOk()->assertSee('What should your card do?');
    }

    public function test_links_mode_one_link_still_redirects(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);

        $this->get('/n/'.$card->public_id)->assertStatus(302)->assertRedirect('https://example.com/a');
    }

    public function test_links_mode_two_links_still_render_the_landing_page(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('A')->assertSee('B');
    }

    // ── Contact card ────────────────────────────────────────────────────────

    public function test_contact_page_renders_for_contact_mode(): void
    {
        $card = $this->card();
        $this->profileFor($card, ['job_title' => 'Director', 'company' => 'Acme Ltd']);
        $card->forceFill(['nfc_mode' => 'contact'])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('John Doe')
            ->assertSee('Director')
            ->assertSee('Acme Ltd')
            ->assertSee('Save to Contacts');
    }

    public function test_contact_page_shows_only_populated_fields(): void
    {
        $card = $this->card();
        $this->profileFor($card, ['mobile_phone' => '+639171234567']);
        $card->forceFill(['nfc_mode' => 'contact'])->save();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('Mobile', $body);
        $this->assertStringNotContainsString('Landline', $body);
        $this->assertStringNotContainsString('Birthday', $body);
        $this->assertStringNotContainsString('Social profiles', $body);
        $this->assertStringNotContainsString('Notes', $body);
    }

    public function test_contact_mode_without_a_name_shows_the_setup_chooser(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'contact'])->save();

        // An unconfigured card is the owner's starting point in every visible
        // mode. The profile leads, and the two hidden modes - Email, and Phone
        // as a contact card with one field - are absent.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('What should your card do?')
            ->assertSee('Profile page')
            ->assertSee('Contact card')
            ->assertDontSee('Phone number')
            ->assertDontSee('Email address');
    }

    public function test_public_setup_post_is_rejected_when_the_current_mode_is_configured(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'contact'])->save();
        $card->contactProfile()->save($card->contactProfile()->make(['first_name' => 'Live']));

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'links'          => [['title' => 'X', 'url' => 'https://example.com/x']],
        ])->assertRedirect('/n/'.$card->public_id);

        $this->assertSame(0, $card->fresh()->nfcLinks()->count());
        $this->assertSame(NfcMode::Contact, $card->fresh()->nfcMode());
    }

    public function test_an_unconfigured_card_in_another_mode_can_become_a_links_card(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'contact'])->save();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'links'          => [['title' => 'X', 'url' => 'https://example.com/x']],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $fresh = $card->fresh();
        $this->assertSame(NfcMode::Links, $fresh->nfcMode());
        $this->assertSame(1, $fresh->nfcLinks()->count());
    }

    public function test_the_phone_setup_form_configures_an_unconfigured_card(): void
    {
        $card = $this->card();

        $this->get('/n/'.$card->public_id.'/setup/phone')->assertOk()->assertSee('Activation PIN');

        $this->post('/n/'.$card->public_id.'/setup/phone', [
            'activation_pin'   => '000000',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'nfc_phone_number' => '+63 917 123 4567',
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $fresh = $card->fresh();
        $this->assertSame(NfcMode::Phone, $fresh->nfcMode());
        $this->assertSame('+63 917 123 4567', $fresh->nfc_phone_number);
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('Call Now');
    }

    public function test_the_phone_setup_rejects_a_wrong_pin_and_saves_nothing(): void
    {
        $card = $this->card();

        $this->from('/n/'.$card->public_id.'/setup/phone')->post('/n/'.$card->public_id.'/setup/phone', [
            'activation_pin'   => '999999',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'nfc_phone_number' => '+63 917 123 4567',
        ])->assertSessionHasErrors('activation_pin');

        $this->assertSame(NfcMode::Links, $card->fresh()->nfcMode());
        $this->assertNull($card->fresh()->nfc_phone_number);
    }

    public function test_the_contact_setup_form_configures_an_unconfigured_card(): void
    {
        $card = $this->card();

        $this->get('/n/'.$card->public_id.'/setup/contact')->assertOk()->assertSee('Activation PIN');

        $this->post('/n/'.$card->public_id.'/setup/contact', [
            'activation_pin' => '000000',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'first_name'     => 'Maria',
            'last_name'      => 'Santos',
            'job_title'      => 'Owner',
            'mobile_phone'   => '+63 917 000 1111',
            'personal_email' => 'maria@example.com',
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $fresh = $card->fresh();
        $this->assertSame(NfcMode::Contact, $fresh->nfcMode());
        $this->assertSame('Maria', $fresh->contactProfile->first_name);
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('Maria Santos')->assertSee('Save to Contacts');
    }

    public function test_the_contact_setup_requires_a_name_or_company(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id.'/setup/contact', [
            'activation_pin' => '000000',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'mobile_phone'   => '+63 917 000 1111',
        ])->assertSessionHasErrors(['first_name', 'company']);

        $this->assertSame(NfcMode::Links, $card->fresh()->nfcMode());
    }

    public function test_the_admin_mode_selector_hides_email_except_on_an_email_card(): void
    {
        $card = $this->card();

        // Hidden, not removed: no fresh card can be switched to Email...
        $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id.'?edit=1')
            ->assertOk()
            ->assertDontSee('value="email"', false);

        // ...but a card already serving it keeps the option visible.
        $card->forceFill(['nfc_mode' => 'email', 'nfc_email_address' => 'hi@example.com'])->save();
        $this->actingAs($this->admin())
            ->get('/admin/cards/'.$card->public_id.'?edit=1')
            ->assertOk()
            ->assertSee('value="email"', false);
    }

    public function test_setup_pages_redirect_away_from_a_configured_card(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);

        $this->get('/n/'.$card->public_id.'/setup/phone')->assertRedirect('/n/'.$card->public_id);
        $this->get('/n/'.$card->public_id.'/setup/contact')->assertRedirect('/n/'.$card->public_id);
        $this->get('/n/'.$card->public_id.'/setup/links')->assertRedirect('/n/'.$card->public_id);

        $this->post('/n/'.$card->public_id.'/setup/phone', [
            'activation_pin' => '000000', 'nfc_phone_number' => '+63 917 123 4567',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ])->assertRedirect('/n/'.$card->public_id);
        $this->assertNull($card->fresh()->nfc_phone_number);
    }

    // ── Phone ───────────────────────────────────────────────────────────────

    public function test_phone_mode_renders_a_tel_action(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'phone', 'nfc_phone_number' => '+63 917 123 4567'])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Call Now')
            ->assertSee('+63 917 123 4567')
            ->assertSee('tel:+639171234567', false);
    }

    public function test_phone_mode_without_a_number_is_not_ready(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'phone'])->save();

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('What should your card do?');
    }

    /** @return array<int, array{string}> */
    public static function badPhones(): array
    {
        return [
            ['javascript:alert(1)'], ['tel:+123;evil'], ['<script>'], ['12345'],
            ['+63 917 +123'], ["+639171234567\r\nX-Injected: 1"], ['tel://x'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPhones')]
    public function test_malformed_phone_numbers_are_rejected(string $phone): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->from('/admin/cards/'.$card->public_id)
            ->patch('/admin/cards/'.$card->public_id.'/phone', ['nfc_phone_number' => $phone])
            ->assertSessionHasErrors('nfc_phone_number');

        $this->assertNull($card->fresh()->nfc_phone_number);
    }

    public function test_valid_phone_is_accepted(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/phone', ['nfc_phone_number' => '+63 (917) 123-4567']);

        $this->assertSame('+63 (917) 123-4567', $card->fresh()->nfc_phone_number);
    }

    // ── Email ───────────────────────────────────────────────────────────────

    public function test_email_mode_encodes_subject_and_body(): void
    {
        $card = $this->card();
        $card->forceFill([
            'nfc_mode'          => 'email',
            'nfc_email_address' => 'hello@example.com',
            'nfc_email_subject' => 'Hello & welcome',
            'nfc_email_body'    => "Line one\nLine two + more",
        ])->save();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('mailto:hello%40example.com', $body);
        $this->assertStringContainsString('subject=Hello%20%26%20welcome', $body);
        $this->assertStringContainsString('body=Line%20one%0ALine%20two%20%2B%20more', $body);
        $this->assertStringContainsString('Send Email', $body);
    }

    public function test_invalid_email_address_is_rejected(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->from('/admin/cards/'.$card->public_id)
            ->patch('/admin/cards/'.$card->public_id.'/email', ['nfc_email_address' => 'not-an-email'])
            ->assertSessionHasErrors('nfc_email_address');

        $this->assertNull($card->fresh()->nfc_email_address);
    }

    /**
     * "0" is falsy in PHP, so `stripControl(...) ?: null` turned a subject line
     * of 0 into NULL and the saved email action lost it silently, reporting
     * success. Short numeric subjects - a ticket number, a year, a table number
     * at an event - are exactly what a card like this gets used for.
     */
    public function test_an_email_subject_of_zero_is_saved_rather_than_dropped(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->from('/admin/cards/'.$card->public_id)
            ->patch('/admin/cards/'.$card->public_id.'/email', [
                'nfc_email_address' => 'hello@example.com',
                'nfc_email_subject' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('0', $card->fresh()->nfc_email_subject);
    }

    public function test_an_email_subject_of_only_whitespace_is_saved_as_nothing(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())
            ->patch('/admin/cards/'.$card->public_id.'/email', [
                'nfc_email_address' => 'hello@example.com',
                'nfc_email_subject' => "  \t ",
            ]);

        $this->assertNull($card->fresh()->nfc_email_subject);
    }

    // ── Mode switching is lossless ──────────────────────────────────────────

    public function test_switching_modes_preserves_every_modes_data(): void
    {
        $card = $this->card();
        $admin = $this->admin();

        // Populate all four modes.
        $card->nfcLinks()->create(['title' => 'Site', 'url' => 'https://example.com/s', 'sort_order' => 0, 'is_active' => true]);
        $this->profileFor($card, ['company' => 'Acme Ltd']);
        $card->forceFill([
            'nfc_phone_number'  => '+639171234567',
            'nfc_email_address' => 'hi@example.com',
        ])->save();

        $originalPublicId = $card->public_id;
        $originalNfcUrl   = $card->nfcUrl();

        foreach (['contact', 'links', 'phone', 'email', 'contact'] as $mode) {
            $this->actingAs($admin)->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => $mode]);
        }

        $card->refresh();

        $this->assertSame(NfcMode::Contact, $card->nfcMode());
        $this->assertSame(1, $card->nfcLinks()->count(), 'links must survive mode switching');
        $this->assertNotNull($card->contactProfile, 'contact profile must survive mode switching');
        $this->assertSame('John Doe', $card->contactProfile->displayName());
        $this->assertSame('+639171234567', $card->nfc_phone_number);
        $this->assertSame('hi@example.com', $card->nfc_email_address);

        // The permanent identity is untouched.
        $this->assertSame($originalPublicId, $card->public_id);
        $this->assertSame($originalNfcUrl, $card->nfcUrl());
    }

    public function test_changing_mode_never_changes_the_public_id_or_permanent_urls(): void
    {
        $card = $this->card();
        $pid = $card->public_id; $nfc = $card->nfcUrl(); $qr = $card->qrUrl();

        foreach (NfcMode::all() as $mode) {
            $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => $mode->value]);
            $card->refresh();
            $this->assertSame($pid, $card->public_id);
            $this->assertSame($nfc, $card->nfcUrl());
            $this->assertSame($qr, $card->qrUrl());
        }
    }

    public function test_deleting_contact_details_is_explicit_only(): void
    {
        $card = $this->card();
        $this->profileFor($card);

        $this->actingAs($this->admin())->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => 'links']);
        $this->assertNotNull($card->fresh()->contactProfile, 'mode switch must not delete');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/contact');
        $this->assertNull($card->fresh()->contactProfile, 'explicit delete must work');
    }

    public function test_an_invalid_mode_value_is_rejected(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->from('/admin/cards/'.$card->public_id)
            ->patch('/admin/cards/'.$card->public_id.'/nfc-mode', ['nfc_mode' => 'wormhole'])
            ->assertSessionHasErrors('nfc_mode');

        $this->assertSame(NfcMode::Links, $card->fresh()->nfcMode());
    }

    // ── Programming target is unchanged ─────────────────────────────────────

    public function test_program_nfc_still_targets_only_the_permanent_url(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'contact', 'nfc_phone_number' => '+639171234567', 'nfc_email_address' => 'x@example.com'])->save();
        $this->profileFor($card, ['mobile_phone' => '+639171234567']);

        $body = $this->actingAs($this->admin())
            ->get('/admin/nfc-tools?card='.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('data-url="'.$card->nfcUrl().'"', $body);
        $this->assertStringNotContainsString('data-url="tel:', $body);
        $this->assertStringNotContainsString('data-url="mailto:', $body);
        $this->assertStringNotContainsString('BEGIN:VCARD', $body);
    }

    // ── QR is untouched ─────────────────────────────────────────────────────

    public function test_nfc_modes_do_not_affect_qr_behaviour(): void
    {
        $card = $this->card();
        $card->forceFill([
            'nfc_mode'       => 'contact',
            'qr_destination' => 'https://example.com/qr',
            'qr_configured_at' => now(),
        ])->save();
        $this->profileFor($card);

        $this->get('/q/'.$card->public_id)->assertStatus(302)->assertRedirect('https://example.com/qr');
    }
}

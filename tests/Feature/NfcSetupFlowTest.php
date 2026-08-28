<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setup as a card owner experiences it: pick a type, unlock once, fill in the
 * details - three screens, each asking one thing.
 *
 * The old single screen asked for an activation PIN, a replacement PIN and
 * every detail of the card at the same time, and owners were abandoning it.
 * These tests pin the split, and pin that the one-page path still works: it is
 * what every request that posts a PIN inline still does.
 */
class NfcSetupFlowTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    // --- Step 1: the chooser -------------------------------------------------

    public function test_the_chooser_offers_a_preview_of_every_card_type(): void
    {
        $card = $this->card();

        $response = $this->get('/n/'.$card->public_id)->assertOk();

        foreach (NfcMode::selfSetup() as $mode) {
            // The eye button and the dialog it opens: an owner who has never
            // seen one of these pages can look before committing.
            $response->assertSee('data-preview="'.$mode->value.'"', false);
            $response->assertSee('id="pv-'.$mode->value.'"', false);
        }

        $response->assertDontSee('data-preview="email"', false);
    }

    public function test_the_profile_page_leads_the_list(): void
    {
        $body = $this->get('/n/'.$this->card()->public_id)->assertOk()->getContent();

        // It is the card type that makes the product look like a product, so
        // it is the first thing an owner is offered.
        $this->assertLessThan(
            strpos($body, NfcMode::Contact->label()),
            strpos($body, NfcMode::Profile->label()),
        );
    }

    /**
     * Phone is a contact card with one field filled in, so it is out of the
     * chooser - but out of the chooser is all it is. A card already serving
     * it keeps working, and its own setup screen still saves.
     */
    public function test_a_hidden_type_is_hidden_and_not_removed(): void
    {
        $this->get('/n/'.$this->card()->public_id)
            ->assertOk()
            ->assertDontSee('data-preview="phone"', false);

        $card = $this->card();

        $this->post('/n/'.$card->public_id.'/setup/phone/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);
        $this->post('/n/'.$card->public_id.'/setup/phone', [
            'nfc_phone_number' => '+63 917 123 4567',
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $this->assertSame(NfcMode::Phone, $card->fresh()->nfcMode());
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('Call Now');
    }

    public function test_the_chooser_speaks_plainly(): void
    {
        $card = $this->card();

        // Words an owner uses, not words a developer uses. "Multi-Link" and
        // "redirect" were the two that came back from customers.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('My links')
            ->assertSee('Contact card')
            ->assertDontSee('Multi-Link')
            ->assertDontSee('redirect');
    }

    // --- Step 2: one unlock, no details --------------------------------------

    public function test_the_setup_screen_asks_for_the_pin_before_any_details(): void
    {
        $card = $this->card();

        $this->get('/n/'.$card->public_id.'/setup/links')
            ->assertOk()
            ->assertSee('name="activation_pin"', false)
            ->assertSee('name="new_pin"', false)
            ->assertDontSee('name="links[0][title]"', false)
            ->assertDontSee('name="page_title"', false);
    }

    public function test_a_correct_pin_opens_the_details_screen_and_the_details_screen_asks_for_no_pin(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id.'/setup/links/unlock', [
            'activation_pin' => '000000',
            'new_pin' => '222222',
            'new_pin_confirmation' => '222222',
        ])->assertRedirect('/n/'.$card->public_id.'/setup/links');

        $this->get('/n/'.$card->public_id.'/setup/links')
            ->assertOk()
            ->assertSee('name="links[0][title]"', false)
            ->assertDontSee('name="activation_pin"', false);
    }

    public function test_the_unlock_step_replaces_the_universal_pin(): void
    {
        $card = $this->card();

        $this->assertFalse($card->pinIsPersonalized());

        $this->post('/n/'.$card->public_id.'/setup/phone/unlock', [
            'activation_pin' => '000000',
            'new_pin' => '345678',
            'new_pin_confirmation' => '345678',
        ])->assertRedirect('/n/'.$card->public_id.'/setup/phone');

        $card->refresh();
        $this->assertTrue($card->pinIsPersonalized());
        $this->assertTrue(app(CardGenerationService::class)->verifyPin($card, '345678'));
        $this->assertFalse(app(CardGenerationService::class)->verifyPin($card, '000000'));
    }

    public function test_a_wrong_pin_does_not_open_the_details_screen(): void
    {
        $card = $this->card();

        $this->from('/n/'.$card->public_id.'/setup/links')
            ->post('/n/'.$card->public_id.'/setup/links/unlock', [
                'activation_pin' => '999999',
                'new_pin' => '222222',
                'new_pin_confirmation' => '222222',
            ])
            ->assertSessionHasErrors('activation_pin');

        $this->get('/n/'.$card->public_id.'/setup/links')
            ->assertOk()
            ->assertSee('name="activation_pin"', false)
            ->assertDontSee('name="links[0][title]"', false);
    }

    // --- Step 3: saving ------------------------------------------------------

    public function test_an_unlocked_owner_saves_without_sending_a_pin_again(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id.'/setup/links/unlock', [
            'activation_pin' => '000000',
            'new_pin' => '222222',
            'new_pin_confirmation' => '222222',
        ])->assertRedirect('/n/'.$card->public_id.'/setup/links');

        $this->post('/n/'.$card->public_id, [
            'links' => [['title' => 'Shop', 'url' => 'https://example.com/shop']],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $fresh = $card->fresh();
        $this->assertSame(NfcMode::Links, $fresh->nfcMode());
        $this->assertSame(1, $fresh->nfcLinks()->count());
    }

    public function test_the_phone_and_contact_screens_also_save_on_the_session_unlock(): void
    {
        $phoneCard = $this->card();
        $this->post('/n/'.$phoneCard->public_id.'/setup/phone/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);
        $this->post('/n/'.$phoneCard->public_id.'/setup/phone', [
            'nfc_phone_number' => '+63 917 123 4567',
        ])->assertRedirect('/n/'.$phoneCard->public_id.'/done');
        $this->assertSame(NfcMode::Phone, $phoneCard->fresh()->nfcMode());

        $contactCard = $this->card();
        $this->post('/n/'.$contactCard->public_id.'/setup/contact/unlock', [
            'activation_pin' => '000000', 'new_pin' => '333333', 'new_pin_confirmation' => '333333',
        ]);
        $this->post('/n/'.$contactCard->public_id.'/setup/contact', [
            'first_name' => 'Maria',
        ])->assertRedirect('/n/'.$contactCard->public_id.'/done');
        $this->assertSame(NfcMode::Contact, $contactCard->fresh()->nfcMode());
    }

    /**
     * The session unlock is what makes the PIN optional - nothing else. A save
     * arriving without either is still refused, so the split screens never
     * became a way around the PIN.
     */
    public function test_a_save_without_an_unlock_and_without_a_pin_is_rejected(): void
    {
        $card = $this->card();

        $this->from('/n/'.$card->public_id)
            ->post('/n/'.$card->public_id, [
                'links' => [['title' => 'Shop', 'url' => 'https://example.com/shop']],
            ])
            // Not "the activation PIN field is required": the details screen
            // has no such field, so that wording describes nothing the owner
            // can see. They are bounced to the unlock screen, and the message
            // has to be about that.
            ->assertSessionHasErrors([
                'activation_pin' => 'Your card locked itself again after a while. Please enter your PIN once more.',
            ]);

        $this->assertSame(0, $card->fresh()->nfcLinks()->count());
    }

    // --- After the save ------------------------------------------------------

    public function test_the_finished_screen_leads_somewhere(): void
    {
        $card = $this->card();

        // It used to say "your card is live" and stop there. The two things an
        // owner wants next are seeing it and changing it.
        $this->get('/n/'.$card->public_id.'/done')
            ->assertOk()
            ->assertSee(route('nfc.show', $card->public_id), false)
            ->assertSee(route('nfc.manage', $card->public_id), false)
            // The ID, without the line that used to explain it on every screen.
            ->assertSee($card->public_id)
            ->assertDontSee('give this ID to support');
    }

    public function test_the_unlock_step_refuses_a_card_that_is_already_live(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'Live', 'url' => 'https://example.com', 'sort_order' => 0, 'is_active' => true]);
        $card->forceFill(['nfc_configured_at' => now()])->save();

        // A live card is only switchable from the manage page, which has its
        // own unlock; the bare public URL must not offer a way in.
        $this->post('/n/'.$card->public_id.'/setup/links/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ])->assertRedirect('/n/'.$card->public_id);
    }

    // --- The live preview ----------------------------------------------------

    public function test_every_details_screen_carries_a_live_preview(): void
    {
        foreach (['links', 'phone', 'contact'] as $mode) {
            $card = $this->card();

            $this->post('/n/'.$card->public_id.'/setup/'.$mode.'/unlock', [
                'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            ]);

            $this->get('/n/'.$card->public_id.'/setup/'.$mode)
                ->assertOk()
                ->assertSee('id="pv-screen"', false)
                ->assertSee('data-kind="'.($mode === 'links' ? 'links' : $mode).'"', false);
        }
    }

    public function test_the_profile_editor_follows_the_same_three_steps(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => NfcMode::Profile])->save();

        // Locked: step 2, the PIN and nothing else.
        $this->get('/n/'.$card->public_id.'/edit')
            ->assertOk()
            ->assertSee('2. Unlock card')
            ->assertDontSee('id="pv-screen"', false);

        $this->post('/n/'.$card->public_id.'/edit/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);

        $this->get('/n/'.$card->public_id.'/edit')
            ->assertOk()
            ->assertSee('3. Add details')
            ->assertSee('data-kind="profile"', false)
            ->assertSee('id="pv-screen"', false);
    }

    public function test_the_manage_page_previews_a_type_before_the_owner_switches_to_it(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'Live', 'url' => 'https://example.com', 'sort_order' => 0, 'is_active' => true]);
        $card->forceFill(['nfc_configured_at' => now()])->save();

        // Locked, the page is a PIN box; the previews come with the unlock.
        $this->get('/n/'.$card->public_id.'/manage')->assertOk()->assertDontSee('data-preview=', false);

        $this->post('/n/'.$card->public_id.'/manage/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);

        $response = $this->get('/n/'.$card->public_id.'/manage')->assertOk();

        foreach (NfcMode::selfSetup() as $mode) {
            $response->assertSee('data-preview="'.$mode->value.'"', false);
        }
    }

    /**
     * Mid-switch, "pick a different type" has to lead back to the list of
     * types. For a live card that list is on the manage page: /n/{id} serves
     * the card itself, so sending them there drops them on the very page they
     * were trying to change.
     */
    public function test_the_back_link_leads_to_the_list_of_types_that_actually_exists(): void
    {
        $fresh = $this->card();
        $this->get('/n/'.$fresh->public_id.'/setup/links')
            ->assertOk()
            ->assertSee(route('nfc.show', $fresh->public_id), false);

        $live = $this->card();
        $live->nfcLinks()->create(['title' => 'Live', 'url' => 'https://example.com', 'sort_order' => 0, 'is_active' => true]);
        $live->forceFill(['nfc_configured_at' => now()])->save();

        $this->post('/n/'.$live->public_id.'/manage/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);

        $this->get('/n/'.$live->public_id.'/setup/contact')
            ->assertOk()
            ->assertSee(route('nfc.manage', $live->public_id), false);
    }

    /**
     * The way out rides in the actions row beside the real button. It used to
     * be a lone "← Back to my card" line under every form, and a line per
     * screen is what the owner screens were being asked to stop spending.
     */
    public function test_the_way_out_shares_a_row_with_the_action(): void
    {
        $card = $this->card();

        $this->get('/n/'.$card->public_id.'/setup/links')
            ->assertOk()
            ->assertSee('class="actions"', false)
            ->assertSee('class="btn-2"', false)
            ->assertDontSee('&larr;', false);

        $this->get('/n/'.$card->public_id.'/done')
            ->assertOk()
            ->assertSee('class="actions"', false)
            ->assertDontSee('&larr;', false);

        // The manage page's only action is a form deep in the page, so its
        // exit rides in the heading instead - still no line of its own.
        $this->get('/n/'.$card->public_id.'/manage')
            ->assertOk()
            ->assertSee('class="head"', false)
            ->assertDontSee('&larr;', false);
    }

    /**
     * The preview is rendered ABOVE the form - on a phone it rides along the
     * top of the screen - so its script runs before a single field it reads
     * exists. It has to wait for the parse, or a card that already has
     * details draws an empty phone until the owner touches an input.
     */
    public function test_the_preview_waits_for_the_form_it_reads(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id.'/setup/links/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);

        $body = $this->get('/n/'.$card->public_id.'/setup/links')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'name="links[0][title]"'),
            strpos($body, 'id="pv-screen"'),
            'the preview comes first in the markup, which is why the wait is needed'
        );
        $this->assertStringContainsString('DOMContentLoaded', $body);
    }

    public function test_the_progress_bar_counts_the_three_steps(): void
    {
        $card = $this->card();

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('1. Pick a type');
        $this->get('/n/'.$card->public_id.'/setup/links')->assertOk()->assertSee('2. Unlock card');

        $this->post('/n/'.$card->public_id.'/setup/links/unlock', [
            'activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222',
        ]);

        $this->get('/n/'.$card->public_id.'/setup/links')->assertOk()->assertSee('3. Add details');
    }
}

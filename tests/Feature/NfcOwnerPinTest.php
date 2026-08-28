<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NfcOwnerPinTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'pin-admin@example.com'],
            ['name' => 'A', 'password' => Hash::make('correct-horse-1')]
        );
    }

    private function liveLinksCard(): Card
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);

        return $card->fresh();
    }

    public function test_setup_replaces_the_universal_pin_with_the_owners_own(): void
    {
        $card = $this->card();
        $service = app(CardGenerationService::class);

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'new_pin' => '445566', 'new_pin_confirmation' => '445566',
            'links' => [['title' => 'Site', 'url' => 'https://example.com']],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $fresh = $card->fresh();
        $this->assertTrue($fresh->pinIsPersonalized());
        $this->assertFalse($service->verifyPin($fresh, '000000'), 'the universal PIN must stop working');
        $this->assertTrue($service->verifyPin($fresh, '445566'));
        $this->assertSame('445566', $service->revealPin($fresh), 'admin reveal shows the personal PIN');
    }

    public function test_the_default_pin_and_a_mismatched_confirmation_are_rejected(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'new_pin' => '000000', 'new_pin_confirmation' => '000000',
            'links' => [['title' => 'Site', 'url' => 'https://example.com']],
        ])->assertSessionHasErrors('new_pin');

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'new_pin' => '111111', 'new_pin_confirmation' => '222222',
            'links' => [['title' => 'Site', 'url' => 'https://example.com']],
        ])->assertSessionHasErrors('new_pin');

        $this->assertFalse($card->fresh()->pinIsPersonalized());
        $this->assertSame(0, $card->fresh()->nfcLinks()->count());
    }

    public function test_a_personalized_card_sets_up_again_without_a_new_pin_field(): void
    {
        $card = $this->card();
        app(CardGenerationService::class)->personalizePin($card, '445566');

        // e.g. after an admin content reset: the personal PIN alone suffices.
        $this->post('/n/'.$card->fresh()->public_id, [
            'activation_pin' => '445566',
            'links' => [['title' => 'Site', 'url' => 'https://example.com']],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $this->assertSame(1, $card->fresh()->nfcLinks()->count());
    }

    public function test_manage_unlock_personalizes_a_legacy_universal_card_and_allows_mode_switch(): void
    {
        $card = $this->liveLinksCard();

        // Locked: the manage page shows only the PIN gate.
        $this->get('/n/'.$card->public_id.'/manage')
            ->assertOk()
            ->assertSee('name="activation_pin"', false)
            ->assertDontSee('Change PIN');

        // A live card in another mode refuses setup without the unlock.
        $this->get('/n/'.$card->public_id.'/setup/phone')->assertRedirect('/n/'.$card->public_id);

        // Unlock on a universal-PIN card demands a personal PIN...
        $this->post('/n/'.$card->public_id.'/manage/unlock', ['activation_pin' => '000000'])
            ->assertSessionHasErrors('new_pin');

        $this->post('/n/'.$card->public_id.'/manage/unlock', [
            'activation_pin' => '000000',
            'new_pin' => '445566', 'new_pin_confirmation' => '445566',
        ])->assertRedirect('/n/'.$card->public_id.'/manage');

        $this->assertTrue($card->fresh()->pinIsPersonalized());

        // ...and the unlock opens the manage page and the setup forms.
        $this->get('/n/'.$card->public_id.'/manage')->assertOk()->assertSee('Change PIN');
        $this->get('/n/'.$card->public_id.'/setup/phone')->assertOk();

        $this->post('/n/'.$card->public_id.'/setup/phone', [
            'activation_pin' => '445566',
            'nfc_phone_number' => '+63 917 123 4567',
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $fresh = $card->fresh();
        $this->assertSame(NfcMode::Phone, $fresh->nfcMode());
        // The links survive the switch: switching back later restores them.
        $this->assertSame(1, $fresh->nfcLinks()->count());
    }

    public function test_manage_unlock_rejects_a_wrong_pin(): void
    {
        $card = $this->liveLinksCard();
        app(CardGenerationService::class)->personalizePin($card, '445566');

        $this->from('/n/'.$card->public_id.'/manage')
            ->post('/n/'.$card->public_id.'/manage/unlock', ['activation_pin' => '999999'])
            ->assertSessionHasErrors('activation_pin');

        $this->get('/n/'.$card->public_id.'/setup/phone')->assertRedirect('/n/'.$card->public_id);
    }

    public function test_the_owner_can_change_their_pin_from_the_manage_page(): void
    {
        $card = $this->liveLinksCard();
        $service = app(CardGenerationService::class);
        $service->personalizePin($card, '445566');

        // Change PIN requires the unlock first.
        $this->post('/n/'.$card->public_id.'/manage/pin', [
            'new_pin' => '778899', 'new_pin_confirmation' => '778899',
        ])->assertForbidden();

        $this->post('/n/'.$card->public_id.'/manage/unlock', ['activation_pin' => '445566'])
            ->assertRedirect('/n/'.$card->public_id.'/manage');

        $this->post('/n/'.$card->public_id.'/manage/pin', [
            'new_pin' => '778899', 'new_pin_confirmation' => '778899',
        ])->assertSessionHasNoErrors();

        $fresh = $card->fresh();
        $this->assertFalse($service->verifyPin($fresh, '445566'));
        $this->assertTrue($service->verifyPin($fresh, '778899'));
    }

    public function test_admin_can_reset_a_personalized_pin_back_to_universal(): void
    {
        $card = $this->liveLinksCard();
        $service = app(CardGenerationService::class);
        $service->personalizePin($card, '445566');

        $this->post('/admin/cards/'.$card->public_id.'/pin/reset')->assertRedirect('/admin/login');

        $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/pin/reset')
            ->assertSessionHasNoErrors();

        $fresh = $card->fresh();
        $this->assertFalse($fresh->pinIsPersonalized());
        $this->assertTrue($service->verifyPin($fresh, '000000'));
        $this->assertFalse($service->verifyPin($fresh, '445566'));
    }

    public function test_the_activation_manifest_flags_personalized_pins(): void
    {
        $universal = $this->card();
        $personal = $this->card();
        app(CardGenerationService::class)->personalizePin($personal, '445566');

        $csv = $this->actingAs($this->admin())
            ->post('/admin/export/activation-manifest')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('card_number,public_id,activation_pin,pin_personalized,qr_url,nfc_url', $csv);
        $this->assertStringContainsString('"'.$universal->public_id.'","000000","no"', $csv);
        $this->assertStringContainsString('"'.$personal->public_id.'","445566","yes"', $csv);
    }

    public function test_live_pages_carry_the_manage_link_and_owner_pages_show_the_card_id(): void
    {
        $card = $this->liveLinksCard();
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        // Multi-link landing page offers the owner door.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('/n/'.$card->public_id.'/manage');

        // The chooser and manage page state the card ID for support.
        $fresh = $this->card();
        $this->get('/n/'.$fresh->public_id)->assertOk()->assertSee('Card ID');
        $this->get('/n/'.$card->public_id.'/manage')->assertOk()->assertSee($card->public_id);
    }
}

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
     * destination and a stored contact photo. Mode switching is lossless by
     * design, so this is a realistic state for a re-issued card.
     */
    private function fullyPopulatedCard(): Card
    {
        $card = $this->card();

        $card->nfcLinks()->create([
            'title' => 'Site', 'url' => 'https://example.com/s', 'sort_order' => 0, 'is_active' => true,
        ]);

        Storage::disk('public')->put('contact-photos/old.jpg', 'not-really-a-jpeg');

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

        return $card->fresh();
    }

    private function assertNfcSideIsClear(Card $card): void
    {
        $card->refresh();

        $this->assertSame(0, $card->nfcLinks()->count(), 'links');
        $this->assertNull($card->contactProfile, 'contact profile');
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
}

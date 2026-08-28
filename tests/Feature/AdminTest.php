<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('correct-horse-battery-1'),
        ]);
    }

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    // --- Access control ------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider("adminGetRoutes")]
    public function test_unauthenticated_admin_routes_are_blocked(string $path): void
    {
        $this->get($path)->assertRedirect('/admin/login');
    }

    public static function adminGetRoutes(): array
    {
        return [['/admin'], ['/admin/nfc-tools'], ['/admin/site-pages'], ['/admin/sticker-templates']];
    }

    public function test_unauthenticated_state_changing_actions_are_blocked(): void
    {
        $card = $this->card();

        $this->post('/admin/cards/generate', ['quantity' => 5])->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/qr')->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/nfc')->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/reset')->assertRedirect('/admin/login');
        $this->post('/admin/cards/'.$card->public_id.'/pin/reveal')->assertRedirect('/admin/login');
        $this->post('/admin/export/activation-manifest')->assertRedirect('/admin/login');

        $this->assertSame(1, Card::count(), 'No cards should have been generated.');
    }

    public function test_public_user_cannot_reveal_a_pin(): void
    {
        $card = $this->card();
        $this->post('/admin/cards/'.$card->public_id.'/pin/reveal')->assertRedirect('/admin/login');
    }

    public function test_there_is_no_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    // --- Generation ----------------------------------------------------------

    public function test_generation_creates_exactly_five_cards(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/cards/generate', ['quantity' => 5])
            ->assertRedirect('/admin');

        $this->assertSame(5, Card::count());
    }

    public function test_generated_public_ids_are_unique_and_non_sequential(): void
    {
        $cards = app(CardGenerationService::class)->generate(60);

        $ids = $cards->pluck('public_id');
        $this->assertCount(60, $ids->unique(), 'public_ids must be unique');

        foreach ($ids as $id) {
            $this->assertSame(CardGenerationService::PUBLIC_ID_LENGTH, strlen($id));
            $this->assertMatchesRegularExpression('/^['.CardGenerationService::ALPHABET.']+$/', $id);
        }

        // Card numbers are sequential; public_ids must not be.
        $this->assertSame('CARD-0001', $cards->first()->card_number);
        $this->assertSame('CARD-0060', $cards->last()->card_number);
        $this->assertNotSame($ids->sort()->values()->all(), $ids->values()->all());
    }

    public function test_consecutive_batches_continue_numbering_without_collision(): void
    {
        $svc = app(CardGenerationService::class);

        // Regression: parsing the card number with FILTER_SANITIZE_NUMBER_INT kept the
        // hyphen ("CARD-0050" -> -50), so the SECOND batch restarted and collided.
        $first  = $svc->generate(50);
        $second = $svc->generate(5);
        $third  = $svc->generate(3);

        $this->assertSame('CARD-0001', $first->first()->card_number);
        $this->assertSame('CARD-0050', $first->last()->card_number);
        $this->assertSame('CARD-0051', $second->first()->card_number);
        $this->assertSame('CARD-0055', $second->last()->card_number);
        $this->assertSame('CARD-0056', $third->first()->card_number);
        $this->assertSame('CARD-0058', $third->last()->card_number);

        $this->assertSame(58, Card::count());
        $this->assertSame(58, Card::distinct()->count('card_number'));
        $this->assertSame(58, Card::distinct()->count('public_id'));
    }

    public function test_generation_quantity_is_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/admin')->post('/admin/cards/generate', ['quantity' => 0])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->from('/admin')->post('/admin/cards/generate', ['quantity' => 501])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->from('/admin')->post('/admin/cards/generate', ['quantity' => 'lots'])->assertSessionHasErrors('quantity');

        $this->assertSame(0, Card::count());
    }

    // --- Resets --------------------------------------------------------------

    public function test_qr_reset_does_not_affect_nfc(): void
    {
        $card = $this->card();
        $card->forceFill(['qr_destination' => 'https://example.com/q', 'qr_configured_at' => now()])->save();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->forceFill(['nfc_configured_at' => now(), 'nfc_page_title' => 'Acme'])->save();

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/qr');

        $card->refresh();
        $this->assertNull($card->qr_destination);
        $this->assertNull($card->qr_configured_at);
        $this->assertSame(1, $card->nfcLinks()->count(), 'NFC links must survive a QR reset');
        $this->assertNotNull($card->nfc_configured_at);
        $this->assertSame('Acme', $card->nfc_page_title);
    }

    public function test_nfc_reset_does_not_affect_qr(): void
    {
        $card = $this->card();
        $card->forceFill(['qr_destination' => 'https://example.com/q', 'qr_configured_at' => now()])->save();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->forceFill(['nfc_configured_at' => now(), 'nfc_page_title' => 'Acme'])->save();

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/nfc');

        $card->refresh();
        $this->assertSame(0, $card->nfcLinks()->count());
        $this->assertNull($card->nfc_configured_at);
        $this->assertNull($card->nfc_page_title);
        $this->assertSame('https://example.com/q', $card->qr_destination, 'QR must survive an NFC reset');
        $this->assertNotNull($card->qr_configured_at);
    }

    public function test_full_reset_preserves_permanent_identity(): void
    {
        $card = $this->card();
        $originalPublicId = $card->public_id;
        $originalNumber   = $card->card_number;
        $originalQrUrl    = $card->qrUrl();
        $originalNfcUrl   = $card->nfcUrl();
        $originalPinHash  = $card->activation_pin_hash;

        $card->forceFill(['qr_destination' => 'https://example.com/q', 'qr_configured_at' => now(),
                          'nfc_configured_at' => now(), 'nfc_page_title' => 'Acme'])->save();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');

        $card->refresh();
        $this->assertNull($card->qr_destination);
        $this->assertNull($card->qr_configured_at);
        $this->assertNull($card->nfc_configured_at);
        $this->assertNull($card->nfc_page_title);
        $this->assertSame(0, $card->nfcLinks()->count());

        // Permanent identity survives.
        $this->assertSame($originalPublicId, $card->public_id);
        $this->assertSame($originalNumber, $card->card_number);
        $this->assertSame($originalQrUrl, $card->qrUrl());
        $this->assertSame($originalNfcUrl, $card->nfcUrl());
        $this->assertSame($originalPinHash, $card->activation_pin_hash, 'PIN must not change on a full reset');
    }

    public function test_pin_regeneration_route_no_longer_exists(): void
    {
        $card = $this->card();

        $this->assertNull(
            \Route::getRoutes()->getByName('admin.cards.pin.regenerate'),
            'The PIN regeneration route must be gone - every card uses the universal PIN.'
        );

        $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/pin/regenerate')
            ->assertNotFound();
    }

    public function test_reveal_pin_returns_the_universal_pin(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/pin/reveal')
            ->assertOk()
            ->assertExactJson(['pin' => '000000']);
    }

    public function test_reveal_pin_response_is_not_cacheable(): void
    {
        $card = $this->card();

        $response = $this->actingAs($this->admin())
            ->post('/admin/cards/'.$card->public_id.'/pin/reveal')->assertOk();

        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cc);
        $this->assertStringContainsString('private', $cc);
    }

    public function test_admin_card_page_documents_the_pin_lifecycle(): void
    {
        $card = $this->card();
        $admin = $this->admin();

        // Fresh card: ships on the universal PIN, no reset button yet.
        $this->actingAs($admin)->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertSee('Cards ship with the universal activation PIN')
            ->assertSee('000000')
            ->assertSee('Universal PIN')
            ->assertDontSee('/pin/reset', false)
            ->assertDontSee('Regenerate PIN');

        // Personalized card: badge flips and the support reset appears.
        app(\App\Services\CardGenerationService::class)->personalizePin($card, '445566');
        $this->actingAs($admin)->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertSee('Personalized')
            ->assertSee('/pin/reset', false);
    }

    /** @return array<int, array{int}> */
    public static function generationQuantities(): array
    {
        return [[5], [10], [25], [7]];   // +5, +10, +25 and a custom quantity
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('generationQuantities')]
    public function test_every_generated_card_uses_the_universal_pin(int $quantity): void
    {
        $svc = app(CardGenerationService::class);

        $this->actingAs($this->admin())
            ->post('/admin/cards/generate', ['quantity' => $quantity])
            ->assertRedirect('/admin');

        $this->assertSame($quantity, Card::count());

        foreach (Card::cursor() as $card) {
            $this->assertTrue($svc->verifyPin($card, '000000'), $card->card_number.' should accept 000000');
            $this->assertSame('000000', $svc->revealPin($card), $card->card_number.' should reveal 000000');
        }
    }

    public function test_cli_generated_cards_use_the_universal_pin(): void
    {
        $this->artisan('nfc:cards:generate', ['quantity' => 6])->assertSuccessful();

        $svc = app(CardGenerationService::class);
        $this->assertSame(6, Card::count());

        foreach (Card::cursor() as $card) {
            $this->assertTrue($svc->verifyPin($card, '000000'));
            $this->assertSame('000000', $svc->revealPin($card));
        }
    }

    public function test_no_random_pin_generation_remains(): void
    {
        $svc = app(CardGenerationService::class);

        // 200 calls must all return the same value - proof the randomness is gone.
        $pins = collect(range(1, 200))->map(fn () => $svc->generatePin())->unique();

        $this->assertCount(1, $pins);
        $this->assertSame('000000', $pins->first());
        $this->assertSame('000000', CardGenerationService::UNIVERSAL_PIN);
    }

    public function test_the_migration_moved_existing_cards_onto_the_universal_pin(): void
    {
        // Simulate a legacy card carrying an old random PIN, then re-apply.
        $svc  = app(CardGenerationService::class);
        $card = $this->card();

        $card->forceFill([
            'activation_pin_hash'      => Hash::make('481902'),
            'activation_pin_encrypted' => '481902',
        ])->save();

        $this->assertTrue($svc->verifyPin($card->fresh(), '481902'), 'precondition');

        $svc->applyUniversalPin($card);

        $card->refresh();
        $this->assertTrue($svc->verifyPin($card, '000000'));
        $this->assertSame('000000', $svc->revealPin($card));
        $this->assertFalse($svc->verifyPin($card, '481902'), 'the old PIN must stop working');
    }

    // --- QR assets -----------------------------------------------------------

    public function test_qr_svg_download_is_valid_svg(): void
    {
        $card = $this->card();

        $response = $this->actingAs($this->admin())->get('/admin/cards/'.$card->public_id.'/qr.svg');

        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('attachment; filename="'.$card->assetBasename().'.svg"',
            $response->headers->get('Content-Disposition'));

        $body = $response->getContent();
        $this->assertStringContainsString('<svg', $body);
        $this->assertNotFalse(simplexml_load_string($body), 'SVG must be well-formed XML');
    }

    public function test_qr_png_download_is_a_valid_high_resolution_png(): void
    {
        $card = $this->card();

        $response = $this->actingAs($this->admin())->get('/admin/cards/'.$card->public_id.'/qr.png');

        $response->assertOk()->assertHeader('Content-Type', 'image/png');

        $body = $response->getContent();
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($body, 0, 8), 'PNG magic bytes');

        $info = getimagesizefromstring($body);
        $this->assertNotFalse($info);
        $this->assertGreaterThanOrEqual(1000, $info[0], 'PNG should be print-usable resolution');
        $this->assertSame($info[0], $info[1], 'QR must be square');
    }

    public function test_qr_encodes_the_permanent_url(): void
    {
        $card = $this->card();
        $svg  = app(\App\Services\QrCodeService::class)->svgForCard($card);

        $this->assertNotEmpty($svg);
        $this->assertSame('https://nfc.viteseo.ph/q/'.$card->public_id, $card->qrUrl());
    }

    public function test_activation_manifest_requires_auth_and_is_no_store(): void
    {
        $this->card();

        $this->post('/admin/export/activation-manifest')->assertRedirect('/admin/login');

        $response = $this->actingAs($this->admin())->post('/admin/export/activation-manifest');
        $response->assertOk();
        $this->assertNoStore($response);
        $this->assertStringContainsString('card_number,public_id,activation_pin', $response->getContent());
    }

    public function test_admin_pages_are_never_cached(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin')->assertOk();
        $this->assertNoStore($response);
    }

    public function test_admin_card_page_does_not_leak_the_pin_hash_and_masks_the_value(): void
    {
        $card = $this->card();

        // The page now intentionally documents the universal PIN in help text, but
        // the PIN field itself must still start masked and the hash must never appear.
        $this->actingAs($this->admin())->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertDontSee($card->activation_pin_hash)
            ->assertDontSee($card->getAttributes()['activation_pin_encrypted'])
            ->assertSee('&bull;&bull;&bull;&bull;&bull;&bull;', false);
    }

    /** Symfony normalises and alphabetises Cache-Control, so assert on semantics. */
    /**
     * The admin selector hides the same modes the customer chooser does, so a
     * card cannot be pushed into one an owner could never have chosen - but a
     * card ALREADY in one keeps its option, or the admin could see the mode
     * and not change it.
     */
    public function test_hidden_modes_stay_out_of_the_admin_selector_until_a_card_is_in_one(): void
    {
        $card  = $this->card();
        // Created once: the helper inserts, so a second call collides on the
        // unique email.
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertSee('value="profile"', false)
            ->assertDontSee('value="phone"', false)
            ->assertDontSee('value="email"', false);

        $card->forceFill(['nfc_mode' => \App\Enums\NfcMode::Phone])->save();

        $this->actingAs($admin)
            ->get('/admin/cards/'.$card->public_id)
            ->assertOk()
            ->assertSee('value="phone"', false);
    }

    private function assertNoStore(\Illuminate\Testing\TestResponse $response): void
    {
        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('private', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('no-cache', $cc, "Cache-Control was: {$cc}");
    }
}

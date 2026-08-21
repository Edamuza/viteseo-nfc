<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class QrTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    private function pin(Card $card): string
    {
        return app(CardGenerationService::class)->revealPin($card);
    }

    public function test_unknown_public_id_returns_404(): void
    {
        $this->get('/q/NOSUCHID')->assertNotFound();
    }

    public function test_unconfigured_card_shows_setup_page(): void
    {
        $card = $this->card();

        $this->get('/q/'.$card->public_id)
            ->assertOk()
            ->assertSee('Set up your QR code')
            ->assertSee('Activation PIN');
    }

    public function test_wrong_pin_is_rejected_and_does_not_configure(): void
    {
        $card = $this->card();

        $this->from('/q/'.$card->public_id)
            ->post('/q/'.$card->public_id, [
                'activation_pin' => '123456',   // 000000 is now the valid universal PIN
                'destination'    => 'https://example.com',
            ])
            ->assertSessionHasErrors('activation_pin');

        $this->assertNull($card->fresh()->qr_destination);
    }

    public function test_valid_pin_configures_exactly_one_destination(): void
    {
        $card = $this->card();

        $this->post('/q/'.$card->public_id, [
            'activation_pin' => $this->pin($card),
            'destination'    => 'https://example.com/landing',
        ])->assertRedirect('/q/'.$card->public_id.'/done');

        $card->refresh();
        $this->assertSame('https://example.com/landing', $card->qr_destination);
        $this->assertNotNull($card->qr_configured_at);
    }

    public function test_universal_pin_000000_activates_the_qr(): void
    {
        $card = $this->card();

        // Literal PIN, not read back from the card - proves the universal value works.
        $this->post('/q/'.$card->public_id, [
            'activation_pin' => '000000',
            'destination'    => 'https://example.com/universal',
        ])->assertRedirect('/q/'.$card->public_id.'/done');

        $card->refresh();
        $this->assertSame('https://example.com/universal', $card->qr_destination);
        $this->assertNotNull($card->qr_configured_at);
    }

    public function test_a_wrong_pin_still_fails_even_though_the_pin_is_universal(): void
    {
        $card = $this->card();

        foreach (['123456', '000001', '00000', 'abcdef'] as $wrong) {
            $this->from('/q/'.$card->public_id)
                ->post('/q/'.$card->public_id, [
                    'activation_pin' => $wrong,
                    'destination'    => 'https://example.com',
                ])
                ->assertSessionHasErrors();

            $this->assertNull($card->fresh()->qr_destination, "PIN {$wrong} must not configure the card");
        }
    }

    public function test_configured_card_redirects_with_302_not_301(): void
    {
        $card = $this->card();
        $card->forceFill(['qr_destination' => 'https://example.com/go', 'qr_configured_at' => now()])->save();

        $response = $this->get('/q/'.$card->public_id);

        $response->assertStatus(302);
        $response->assertRedirect('https://example.com/go');
        $this->assertNotSame(301, $response->getStatusCode());
    }

    public function test_configured_card_never_shows_the_setup_form_publicly(): void
    {
        $card = $this->card();
        $card->forceFill(['qr_destination' => 'https://example.com/go', 'qr_configured_at' => now()])->save();

        $this->get('/q/'.$card->public_id)->assertDontSee('Activation PIN');

        // A second setup attempt must not overwrite the destination.
        $this->post('/q/'.$card->public_id, [
            'activation_pin' => $this->pin($card),
            'destination'    => 'https://evil.example.com',
        ]);

        $this->assertSame('https://example.com/go', $card->fresh()->qr_destination);
    }

    public function test_reset_returns_the_card_to_the_setup_page(): void
    {
        $card = $this->card();
        $card->forceFill(['qr_destination' => 'https://example.com/go', 'qr_configured_at' => now()])->save();
        $card->forceFill(['qr_destination' => null, 'qr_configured_at' => null])->save();

        $this->get('/q/'.$card->public_id)->assertOk()->assertSee('Activation PIN');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("unsafeSchemes")]
    public function test_unsafe_url_schemes_are_rejected(string $url): void
    {
        $card = $this->card();

        $this->from('/q/'.$card->public_id)
            ->post('/q/'.$card->public_id, [
                'activation_pin' => $this->pin($card),
                'destination'    => $url,
            ])
            ->assertSessionHasErrors('destination');

        $this->assertNull($card->fresh()->qr_destination);
    }

    public static function unsafeSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data'       => ['data:text/html;base64,PHNjcmlwdD4='],
            'file'       => ['file:///etc/passwd'],
            'ftp'        => ['ftp://example.com/x'],
            'vbscript'   => ['vbscript:msgbox(1)'],
            'no scheme'  => ['example.com'],
            'bare host'  => ['http://localhost'],
        ];
    }

    public function test_public_qr_responses_send_no_store(): void
    {
        $card = $this->card();

        $this->assertNoStore($this->get('/q/'.$card->public_id));

        $card->forceFill(['qr_destination' => 'https://example.com', 'qr_configured_at' => now()])->save();
        $this->assertNoStore($this->get('/q/'.$card->public_id));
    }

    public function test_pin_attempts_are_rate_limited(): void
    {
        RateLimiter::clear('qr|x|127.0.0.1');
        $card = $this->card();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/q/'.$card->public_id)->post('/q/'.$card->public_id, [
                'activation_pin' => '111111',
                'destination'    => 'https://example.com',
            ]);
        }

        $this->from('/q/'.$card->public_id)
            ->post('/q/'.$card->public_id, [
                'activation_pin' => $this->pin($card),
                'destination'    => 'https://example.com',
            ])
            ->assertSessionHasErrors('activation_pin');

        // Even a correct PIN must not get through while throttled.
        $this->assertNull($card->fresh()->qr_destination);
    }

    /** Symfony normalises and alphabetises Cache-Control, so assert on semantics. */
    private function assertNoStore(\Illuminate\Testing\TestResponse $response): void
    {
        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('private', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('no-cache', $cc, "Cache-Control was: {$cc}");
    }
}

<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    public function test_public_pages_never_expose_admin_surface_or_internals(): void
    {
        $card = $this->card();
        app(CardGenerationService::class)->generate(3);   // other cards exist


        foreach (['/q/'.$card->public_id, '/n/'.$card->public_id] as $url) {
            $body = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('/admin', $body, "Admin surface leaked on {$url}");
            $this->assertStringNotContainsString($card->card_number, $body, "Card number leaked on {$url}");
            $this->assertStringNotContainsString('"id":'.$card->id, $body);
            $this->assertStringNotContainsString($card->activation_pin_hash, $body);
            $this->assertStringNotContainsString('Dashboard', $body);
            $this->assertStringNotContainsString('Generate', $body);
        }
    }

    public function test_public_landing_page_exposes_no_card_internals(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString($card->card_number, $body);
        $this->assertStringNotContainsString('Activation', $body);
        $this->assertStringNotContainsString('/admin', $body);
    }

    public function test_csrf_middleware_is_registered_on_public_post_routes(): void
    {
        // Laravel's test client bypasses CSRF by design, so assert the middleware
        // is actually attached to the route rather than trying to defeat it.
        foreach (['qr.store', 'nfc.store', 'admin.login.attempt', 'admin.cards.generate'] as $name) {
            $route = \Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} is missing");

            $middleware = $route->gatherMiddleware();
            $hasCsrf = collect($middleware)->contains(
                fn ($m) => is_string($m) && (str_contains($m, 'VerifyCsrfToken') || str_contains($m, 'PreventRequestForgery') || $m === 'web')
            );

            $this->assertTrue($hasCsrf, "Route {$name} is not CSRF-protected. Middleware: ".implode(',', array_map('strval', $middleware)));
        }
    }

    public function test_blade_templates_escape_output_by_default(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_page_title' => '"><script>alert(1)</script>'])->save();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("unsafeUrls")]
    public function test_url_rule_rejects_unsafe_input(string $url): void
    {
        // Mirror real usage: the rule is always paired with `required`, which is what
        // rejects an empty value (Laravel skips non-implicit rules on empty input).
        $v = Validator::make(['u' => $url], ['u' => ['required', new SafeExternalUrl()]]);
        $this->assertTrue($v->fails(), "Should have rejected: {$url}");
    }

    public static function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JavaScript:alert(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['file:///etc/passwd'],
            ['ftp://example.com'],
            ['gopher://example.com'],
            ['mailto:a@b.com'],
            ['tel:12345'],
            ['//example.com'],
            ['http://localhost'],
            ['http://'],
            [''],
            ["https://example.com/\r\nSet-Cookie: x=1"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("safeUrls")]
    public function test_url_rule_accepts_legitimate_urls(string $url): void
    {
        $v = Validator::make(['u' => $url], ['u' => [new SafeExternalUrl()]]);
        $this->assertFalse($v->fails(), "Should have accepted: {$url}");
    }

    public static function safeUrls(): array
    {
        return [
            ['https://example.com'],
            ['https://example.com/path?a=b#c'],
            ['http://legacy-partner.example.org/page'],
            ['https://sub.domain.example.co.uk/x'],
        ];
    }

    public function test_pin_is_never_stored_in_plaintext_column(): void
    {
        $card = $this->card();
        $pin  = app(CardGenerationService::class)->revealPin($card);

        $raw = \DB::table('cards')->where('id', $card->id)->first();

        $this->assertStringNotContainsString($pin, $raw->activation_pin_encrypted,
            'Encrypted column must not contain the plaintext PIN');
        $this->assertStringNotContainsString($pin, $raw->activation_pin_hash);
        $this->assertTrue(Hash::check($pin, $raw->activation_pin_hash), 'Hash must verify the PIN');
    }

    public function test_pin_is_the_universal_six_digit_value(): void
    {
        // Superseded the old entropy test: PINs are universal by product decision.
        $svc = app(CardGenerationService::class);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $svc->generatePin());
        $this->assertSame('000000', $svc->generatePin());
    }

    public function test_rate_limiting_still_applies_despite_the_universal_pin(): void
    {
        // The universal PIN is an anti-accident guard, not a secret, so throttling
        // remains the control that stops automated configuration of many cards.
        $card = $this->card();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/q/'.$card->public_id)->post('/q/'.$card->public_id, [
                'activation_pin' => '999999',
                'destination'    => 'https://example.com',
            ]);
        }

        // Even the CORRECT universal PIN must be refused while throttled.
        $this->from('/q/'.$card->public_id)
            ->post('/q/'.$card->public_id, [
                'activation_pin' => '000000',
                'destination'    => 'https://example.com',
            ])
            ->assertSessionHasErrors('activation_pin');

        $this->assertNull($card->fresh()->qr_destination);
    }

    public function test_public_pages_never_disclose_the_activation_pin(): void
    {
        $card = $this->card();

        foreach (['/q/'.$card->public_id, '/n/'.$card->public_id] as $url) {
            $body = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('000000', $body,
                "The PIN must not be pre-filled or disclosed on {$url}");
        }
    }

    public function test_no_state_changing_get_routes_exist(): void
    {
        $offenders = [];

        foreach (\Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (preg_match('/(reset|delete|destroy|generate|regenerate|logout|create|store|update)/i', $uri)) {
                $offenders[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $offenders, 'These GET routes look state-changing: '.implode(', ', $offenders));
    }

    public function test_admin_login_is_rate_limited(): void
    {
        User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('correct-horse-1')]);

        for ($i = 0; $i < 6; $i++) {
            $response = $this->from('/admin/login')->post('/admin/login', [
                'email' => 'a@example.com', 'password' => 'wrong-password',
            ]);
        }

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // Correct credentials must still be refused while throttled.
        $this->from('/admin/login')->post('/admin/login', [
            'email' => 'a@example.com', 'password' => 'correct-horse-1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_card_route_binding_uses_public_id_not_numeric_id(): void
    {
        $card = $this->card();

        // The internal numeric id must not resolve a card.
        $this->get('/q/'.$card->id)->assertNotFound();
        $this->get('/n/'.$card->id)->assertNotFound();

        $this->get('/q/'.$card->public_id)->assertOk();
    }
}

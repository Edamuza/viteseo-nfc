<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfcTest extends TestCase
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
        $this->get('/n/NOSUCHID')->assertNotFound();
    }

    public function test_zero_links_shows_the_chooser_and_the_links_form_carries_the_pin(): void
    {
        $card = $this->card();

        // The tap lands on the setup chooser; the PIN lives on the per-type
        // form the chooser links to, not on the chooser itself.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Set up your card')
            ->assertSee('/n/'.$card->public_id.'/setup/links');

        $this->get('/n/'.$card->public_id.'/setup/links')
            ->assertOk()
            ->assertSee('Activation PIN');
    }

    public function test_wrong_pin_is_rejected(): void
    {
        $card = $this->card();

        $this->from('/n/'.$card->public_id)
            ->post('/n/'.$card->public_id, [
                'activation_pin' => '123456',
                'new_pin' => '222222', 'new_pin_confirmation' => '222222',   // 000000 is now the valid universal PIN
                'links'          => [['title' => 'Site', 'url' => 'https://example.com']],
            ])
            ->assertSessionHasErrors('activation_pin');

        $this->assertSame(0, $card->fresh()->nfcLinks()->count());
    }

    public function test_universal_pin_000000_activates_the_nfc(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'links'          => [
                ['title' => 'One', 'url' => 'https://example.com/1'],
                ['title' => 'Two', 'url' => 'https://example.com/2'],
            ],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $this->assertSame(2, $card->fresh()->nfcLinks()->count());
        $this->assertNotNull($card->fresh()->nfc_configured_at);
    }

    public function test_a_wrong_pin_still_fails_on_nfc(): void
    {
        $card = $this->card();

        $this->from('/n/'.$card->public_id)
            ->post('/n/'.$card->public_id, [
                'activation_pin' => '000001',
                'new_pin' => '222222', 'new_pin_confirmation' => '222222',
                'links'          => [['title' => 'X', 'url' => 'https://example.com/x']],
            ])
            ->assertSessionHasErrors('activation_pin');

        $this->assertSame(0, $card->fresh()->nfcLinks()->count());
    }

    public function test_one_active_link_redirects_with_302(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'Site', 'url' => 'https://example.com/one', 'sort_order' => 0, 'is_active' => true]);

        $response = $this->get('/n/'.$card->public_id);

        $response->assertStatus(302);
        $response->assertRedirect('https://example.com/one');
    }

    public function test_two_or_more_links_show_the_landing_page(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_page_title' => 'Acme Coffee', 'nfc_page_subtitle' => 'Open daily'])->save();
        $card->nfcLinks()->create(['title' => 'Instagram', 'url' => 'https://example.com/ig', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Website',   'url' => 'https://example.com/web', 'sort_order' => 1, 'is_active' => true]);

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Acme Coffee')
            ->assertSee('Open daily')
            ->assertSee('Instagram')
            ->assertSee('Website')
            ->assertDontSee('Activation PIN');
    }

    public function test_landing_buttons_carry_the_chosen_icon_and_none_hides_it(): void
    {
        $card = $this->card();
        // Explicit globe on an unknown URL, explicit no-logo on another.
        $card->nfcLinks()->create(['title' => 'Portfolio', 'url' => 'https://my-folio.example', 'icon' => 'web', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Plain', 'url' => 'https://plain.example', 'icon' => 'none', 'sort_order' => 1, 'is_active' => true]);
        // No choice on a recognisable host: detection still applies.
        $card->nfcLinks()->create(['title' => 'GH', 'url' => 'https://github.com/someone', 'sort_order' => 2, 'is_active' => true]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        // Portfolio row: globe icon before the label.
        $this->assertMatchesRegularExpression('/<span class="ic"[^>]*>.*?<\/span>\s*<span class="lt">Portfolio</s', $body);
        // Plain row: label only, no icon span before it.
        $this->assertMatchesRegularExpression('/<a class="link"[^>]*>\s*<span class="lt">Plain</s', $body);
        // GitHub row: the brand mark (its path data starts with M12 .297).
        $this->assertStringContainsString('M12 .297c-6.63', $body);
    }

    public function test_public_links_setup_persists_the_chosen_icon(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => $this->pin($card),
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'links' => [
                ['title' => 'Folio', 'url' => 'https://folio.example', 'icon' => 'web'],
                ['title' => 'Plain', 'url' => 'https://plain.example', 'icon' => 'none'],
                ['title' => 'Auto', 'url' => 'https://github.com/x', 'icon' => ''],
            ],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $this->assertSame(
            ['web', 'none', null],
            $card->fresh()->nfcLinks()->orderBy('sort_order')->pluck('icon')->all()
        );
    }

    public function test_public_links_setup_rejects_an_unknown_icon(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => $this->pin($card),
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'links' => [['title' => 'X', 'url' => 'https://example.com', 'icon' => 'evil']],
        ])->assertSessionHasErrors('links.0.icon');
    }

    public function test_landing_page_omits_the_header_when_no_branding_is_set(): void
    {
        // Both branding fields are optional; leaving them blank is the common path.
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<header>', $body, 'An empty header still applies its bottom margin');
        $this->assertStringContainsString('>A<', $body);
        $this->assertStringContainsString('>B<', $body);

        // ...but it IS rendered when there is something to show.
        $card->forceFill(['nfc_page_title' => 'Acme'])->save();
        $withTitle = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('<header>', $withTitle);
        $this->assertStringContainsString('Acme', $withTitle);
    }

    public function test_links_render_in_sort_order(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'Third',  'url' => 'https://example.com/3', 'sort_order' => 2, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'First',  'url' => 'https://example.com/1', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Second', 'url' => 'https://example.com/2', 'sort_order' => 1, 'is_active' => true]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertLessThan(strpos($body, 'Second'), strpos($body, 'First'));
        $this->assertLessThan(strpos($body, 'Third'),  strpos($body, 'Second'));
    }

    public function test_inactive_links_do_not_count_toward_behaviour(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'Live', 'url' => 'https://example.com/live', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Off',  'url' => 'https://example.com/off',  'sort_order' => 1, 'is_active' => false]);

        // One ACTIVE link => direct redirect, not a landing page.
        $this->get('/n/'.$card->public_id)->assertStatus(302)->assertRedirect('https://example.com/live');
    }

    public function test_valid_pin_configures_multiple_links_in_one_session(): void
    {
        $card = $this->card();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => $this->pin($card),
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'page_title'     => 'Acme',
            'links'          => [
                ['title' => 'One', 'url' => 'https://example.com/1'],
                ['title' => 'Two', 'url' => 'https://example.com/2'],
            ],
        ])->assertRedirect('/n/'.$card->public_id.'/done');

        $card->refresh();
        $this->assertSame(2, $card->nfcLinks()->count());
        $this->assertNotNull($card->nfc_configured_at);
        $this->assertSame('Acme', $card->nfc_page_title);
        $this->assertSame([0, 1], $card->nfcLinks()->pluck('sort_order')->all());
    }

    public function test_configured_card_never_shows_setup_again(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        $this->get('/n/'.$card->public_id)->assertOk()->assertDontSee('Activation PIN');

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => $this->pin($card),
            'new_pin' => '222222', 'new_pin_confirmation' => '222222',
            'links'          => [['title' => 'C', 'url' => 'https://example.com/c']],
        ]);

        $this->assertSame(2, $card->fresh()->nfcLinks()->count());
    }

    public function test_reset_returns_to_setup_page(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->delete();
        $card->forceFill(['nfc_configured_at' => null])->save();

        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('Set up your card');
        $this->get('/n/'.$card->public_id.'/setup/links')->assertOk()->assertSee('Activation PIN');
    }

    public function test_unsafe_schemes_rejected_in_nfc_links(): void
    {
        $card = $this->card();

        $this->from('/n/'.$card->public_id)
            ->post('/n/'.$card->public_id, [
                'activation_pin' => $this->pin($card),
                'new_pin' => '222222', 'new_pin_confirmation' => '222222',
                'links'          => [['title' => 'Bad', 'url' => 'javascript:alert(1)']],
            ])
            ->assertSessionHasErrors('links.0.url');

        $this->assertSame(0, $card->fresh()->nfcLinks()->count());
    }

    public function test_landing_page_escapes_link_titles(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create(['title' => '<script>alert(1)</script>', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Safe', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function test_public_nfc_responses_send_no_store(): void
    {
        $card = $this->card();

        // zero links -> setup page
        $this->assertNoStore($this->get('/n/'.$card->public_id));

        // one link -> redirect
        $card->nfcLinks()->create(['title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true]);
        $this->assertNoStore($this->get('/n/'.$card->public_id));

        // two links -> landing page
        $card->nfcLinks()->create(['title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => true]);
        $this->assertNoStore($this->get('/n/'.$card->public_id));
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

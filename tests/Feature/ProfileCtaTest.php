<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's own button beside Share, painted in the page's colour, and
 * link rows that are the link rather than a printed address.
 */
class ProfileCtaTest extends TestCase
{
    use RefreshDatabase;

    private function card(array $profile = [], ?string $brand = null): Card
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $row = $card->profile()->make(['display_name' => 'Uriel Papa']);
        // forceFill, not fill: a test must be able to plant what no form would
        // accept, to prove the page still refuses to draw it.
        $row->forceFill($profile + ($brand ? ['brand_color' => $brand] : []));
        $card->profile()->save($row);
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        return $card->fresh();
    }

    private function page(Card $card): string
    {
        return $this->get('/n/'.$card->public_id)->assertOk()->getContent();
    }

    private function unlock(Card $card): void
    {
        $this->post('/n/'.$card->public_id.'/edit/unlock', ['activation_pin' => '000000', 'new_pin' => '222222', 'new_pin_confirmation' => '222222'])
            ->assertRedirect('/n/'.$card->public_id.'/edit');
    }

    private function save(Card $card, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->patch('/n/'.$card->public_id.'/edit', $fields + ['display_name' => 'Uriel Papa']);
    }

    // ── On the page ─────────────────────────────────────────────────────────

    public function test_the_owners_button_sits_beside_share_painted_in_the_pages_colour(): void
    {
        $card = $this->card(['cta_label' => 'Book a table', 'cta_url' => 'https://example.com/book'], '#5a4a10');

        $body = $this->page($card);

        $this->assertStringContainsString('--brand:#5a4a10', $body, 'the page colour comes from the photo');
        $this->assertMatchesRegularExpression(
            '#<div class="actions">\s*<a class="sharebtn"[^>]*>Share</a>\s*<a class="ctabtn" href="https://example\.com/book"[^>]*>Book a table</a>\s*</div>#',
            $body,
            'Share on the left, the owner\'s button on the right, in one row'
        );
        $this->assertStringContainsString('background:var(--brand);color:var(--on-brand)', $body);
    }

    public function test_without_a_button_share_keeps_the_row_to_itself(): void
    {
        $body = $this->page($this->card());

        $this->assertStringNotContainsString('class="ctabtn"', $body);
        $this->assertStringContainsString('class="sharebtn" href="#share"', $body);
    }

    public function test_half_a_button_is_never_drawn(): void
    {
        $this->assertStringNotContainsString('class="ctabtn"', $this->page($this->card(['cta_label' => 'Book'])));
        $this->assertStringNotContainsString('class="ctabtn"', $this->page($this->card(['cta_url' => 'https://example.com'])));
    }

    public function test_a_stored_hostile_url_never_becomes_a_button(): void
    {
        $body = $this->page($this->card(['cta_label' => 'Win', 'cta_url' => 'javascript:alert(1)']));

        $this->assertStringNotContainsString('class="ctabtn"', $body);
        $this->assertStringNotContainsString('href="javascript:', $body);
    }

    public function test_the_label_is_escaped(): void
    {
        $body = $this->page($this->card(['cta_label' => '<script>alert(1)</script>', 'cta_url' => 'https://example.com']));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    // ── The owner's editor ──────────────────────────────────────────────────

    public function test_the_owner_editor_offers_both_fields(): void
    {
        $card = $this->card();
        $this->unlock($card);

        $this->get('/n/'.$card->public_id.'/edit')->assertOk()
            ->assertSee('name="cta_label"', false)
            ->assertSee('name="cta_url"', false);
    }

    public function test_the_owner_can_set_the_button(): void
    {
        $card = $this->card();
        $this->unlock($card);

        $this->save($card, ['cta_label' => ' Book a table ', 'cta_url' => 'https://example.com/book'])
            ->assertSessionHasNoErrors();

        $profile = $card->fresh()->profile;
        $this->assertSame('Book a table', $profile->cta_label);
        $this->assertSame('https://example.com/book', $profile->cta_url);
        $this->assertStringContainsString('>Book a table</a>', $this->page($card));
    }

    public function test_both_halves_or_neither(): void
    {
        $card = $this->card();
        $this->unlock($card);

        $this->save($card, ['cta_label' => 'Book'])->assertSessionHasErrors('cta_url');
        $this->save($card, ['cta_url' => 'https://example.com'])->assertSessionHasErrors('cta_label');

        $this->assertNull($card->fresh()->profile->cta_label);
        $this->assertNull($card->fresh()->profile->cta_url);
    }

    public function test_an_unsafe_button_link_is_refused(): void
    {
        $card = $this->card();
        $this->unlock($card);

        foreach (['javascript:alert(1)', 'ftp://example.com/x', 'not a url'] as $url) {
            $this->save($card, ['cta_label' => 'Go', 'cta_url' => $url])->assertSessionHasErrors('cta_url');
        }

        $this->assertNull($card->fresh()->profile->cta_url);
    }

    public function test_the_owner_can_take_the_button_away(): void
    {
        $card = $this->card(['cta_label' => 'Book', 'cta_url' => 'https://example.com']);
        $this->unlock($card);

        $this->save($card, ['cta_label' => '', 'cta_url' => ''])->assertSessionHasNoErrors();

        $this->assertNull($card->fresh()->profile->cta_label);
        $this->assertStringNotContainsString('class="ctabtn"', $this->page($card));
    }

    public function test_an_owner_edit_keeps_the_previous_button_in_its_snapshot(): void
    {
        $card = $this->card(['cta_label' => 'Old', 'cta_url' => 'https://example.com/old']);
        $this->unlock($card);

        $this->save($card, ['cta_label' => 'New', 'cta_url' => 'https://example.com/new'])->assertSessionHasNoErrors();

        $snapshot = $card->fresh()->profileEdits()->first()->snapshot;
        $this->assertSame('Old', $snapshot['profile']['cta_label']);
        $this->assertSame('https://example.com/old', $snapshot['profile']['cta_url']);
    }

    // ── The admin ───────────────────────────────────────────────────────────

    public function test_the_admin_can_set_the_button_and_is_held_to_the_same_rules(): void
    {
        $card = $this->card();
        $admin = User::factory()->create();

        $this->actingAs($admin)->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Uriel Papa', 'cta_label' => 'Book', 'cta_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('cta_url');

        $this->actingAs($admin)->patch('/admin/cards/'.$card->public_id.'/profile', [
            'display_name' => 'Uriel Papa', 'cta_label' => 'Book', 'cta_url' => 'https://example.com/book',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Book', $card->fresh()->profile->cta_label);
    }

    // ── The Links page ──────────────────────────────────────────────────────

    public function test_every_links_page_button_ends_in_an_open_arrow_and_prints_no_address(): void
    {
        $card = app(CardGenerationService::class)->generate(1)->first();
        $card->nfcLinks()->create(['title' => 'Menu', 'url' => 'https://example.com/menu', 'sort_order' => 0, 'is_active' => true]);
        $card->nfcLinks()->create(['title' => 'Order', 'url' => 'https://example.com/order', 'sort_order' => 1, 'is_active' => true]);

        $body = $this->page($card);

        $this->assertSame(2, substr_count($body, '<span class="go" aria-hidden="true">'));
        $this->assertStringContainsString('>Menu</span>', $body);
        $this->assertStringNotContainsString('>https://example.com/menu<', $body);
        $this->assertStringNotContainsString('>example.com/menu<', $body);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The admin shell: a collapsible left rail and a sticky top bar, ported from
 * the platform's own (viteseo-platform2.0, core/layout/Sidebar.tsx).
 *
 * These tests are about the SHELL, not the pages inside it - what every admin
 * page inherits by extending the layout, and the two promises that are easy to
 * break silently: the nav still works with JavaScript off, and the rail's
 * remembered width is applied before the page is painted rather than after.
 */
class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Julius Reyes', 'email' => 'devops@example.com', 'password' => Hash::make('correct-horse-battery-1'),
        ]);
    }

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    public function test_every_admin_page_carries_the_four_nav_rows(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/nfc-tools');

        foreach ([
            route('admin.dashboard'),
            route('admin.nfc-tools'),
            route('admin.site-pages.index'),
            route('admin.sticker-templates.index'),
        ] as $href) {
            $response->assertSee('href="'.$href.'"', false);
        }
    }

    /**
     * @param  string  $path      a page to open
     * @param  string  $lit       the nav row that must be the lit one there
     */
    #[\PHPUnit\Framework\Attributes\DataProvider("navHighlights")]
    public function test_the_row_for_the_current_section_is_the_lit_one(string $path, string $lit): void
    {
        $response = $this->actingAs($this->admin())->get($path);

        // Exactly one row carries the active class, and it is that section's.
        $this->assertSame(1, substr_count($response->getContent(), 'class="nrow on"'));
        $this->assertMatchesRegularExpression(
            '/class="nrow on"[^>]*>.*?<span class="rail-hide">'.preg_quote($lit, '/').'</s',
            $response->getContent(),
        );
    }

    public static function navHighlights(): array
    {
        return [
            'the card list'        => ['/admin', 'Cards'],
            'the NFC tools'        => ['/admin/nfc-tools', 'NFC Tools'],
            'the site pages'       => ['/admin/site-pages', 'Site Pages'],
            'the sticker library'  => ['/admin/sticker-templates', 'Sticker templates'],
        ];
    }

    /** A card page is reached from Cards and belongs under it. */
    public function test_a_card_page_lights_the_cards_row(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin())->get('/admin/cards/'.$card->public_id)
            ->assertSee('class="nrow on"', false)
            ->assertSee('<span class="rail-hide">Cards</span>', false);
    }

    public function test_the_rail_foot_names_the_signed_in_admin_and_signs_them_out(): void
    {
        $this->actingAs($this->admin())->get('/admin')
            ->assertSee('Julius Reyes')
            ->assertSee('devops@example.com')
            // Two letters, from the first and last word of the name.
            ->assertSee('<span class="ava" aria-hidden="true">JR</span>', false)
            ->assertSee('action="'.route('admin.logout').'"', false);
    }

    /**
     * The drawer is a checkbox on purpose. If this ever becomes a button with
     * a click handler, a phone with JavaScript off loses its whole navigation
     * - and the pages behind it are unreachable, not merely unstyled.
     */
    public function test_the_phone_drawer_opens_without_javascript(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin');

        $response->assertSee('<input class="navstate" type="checkbox" id="navstate"', false);
        // Both the hamburger and the backdrop are labels pointing at that box.
        $this->assertSame(3, substr_count($response->getContent(), 'for="navstate"'));
    }

    /**
     * The remembered width is applied in <head>, BEFORE the body, or a rail
     * the admin left collapsed flashes open on every single page load.
     */
    public function test_the_rail_is_restored_before_the_page_is_painted(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin')->getContent();

        $this->assertStringContainsString("localStorage.getItem('nfc-admin-nav')", $html);
        $this->assertLessThan(
            strpos($html, '<body>'),
            strpos($html, "localStorage.getItem('nfc-admin-nav')"),
            'The rail-restoring script must run before the body is parsed.',
        );
    }

    /** The collapse control is only shown once the script that gives it meaning has run. */
    public function test_the_collapse_control_is_hidden_without_javascript(): void
    {
        $this->actingAs($this->admin())->get('/admin')
            ->assertSee('html.js .nav-collapse{display:grid}', false);
    }

    // --- The one search box --------------------------------------------------

    public function test_the_top_bar_searches_cards_from_any_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/sticker-templates')
            ->assertSee('<form class="gsearch" method="GET" action="'.route('admin.dashboard').'"', false)
            ->assertSee('name="search"', false);
    }

    /**
     * One search box, not two. The dashboard's filter form used to own the
     * search input; now it carries the term as a hidden field so narrowing by
     * status or sort keeps the search you already ran.
     */
    public function test_the_card_list_has_no_second_search_box(): void
    {
        $this->card();

        $html = $this->actingAs($this->admin())->get('/admin?search=CARD-0001&status=all')->getContent();

        $this->assertSame(1, substr_count($html, 'type="search"'));
        $this->assertStringContainsString('<input type="hidden" name="search" value="CARD-0001">', $html);
    }

    /** Searching from the dashboard must not silently drop the status filter. */
    public function test_a_search_from_the_card_list_keeps_the_filters_it_was_run_under(): void
    {
        $this->actingAs($this->admin())->get('/admin?status=qr_only&sort=oldest')
            ->assertSee('<input type="hidden" name="status" value="qr_only">', false)
            ->assertSee('<input type="hidden" name="sort" value="oldest">', false);
    }

    /**
     * With nothing searched, the dashboard's filter form carries no hidden
     * field at all - an empty one would submit `search=` and turn every
     * "Apply" into a search for the empty string.
     *
     * Asserted on the hidden field, not on `name="search"`: the top bar's own
     * box is also named that, and it legitimately renders empty.
     */
    public function test_no_search_means_no_hidden_search_field(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin')->getContent();

        $this->assertStringNotContainsString('<input type="hidden" name="search"', $html);
    }

    public function test_the_top_bar_says_which_page_you_are_on(): void
    {
        $this->actingAs($this->admin())->get('/admin/nfc-tools')
            ->assertSee('<span class="topbar-here">NFC Tools</span>', false);
    }

    /** The sign-in page is its own document - no rail, no bar, nothing to sign out of. */
    public function test_the_login_page_has_no_shell(): void
    {
        $this->get('/admin/login')
            ->assertDontSee('class="sidebar"', false)
            ->assertDontSee('id="navstate"', false);
    }
}

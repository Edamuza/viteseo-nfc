<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\NfcProfile;
use App\Services\CardGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class NfcProfileTest extends TestCase
{
    use RefreshDatabase;

    private function card(): Card
    {
        return app(CardGenerationService::class)->generate(1)->first();
    }

    public function test_a_card_has_one_profile(): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa']));

        $this->assertSame('Uriel Papa', $card->fresh()->profile->display_name);
    }

    /** @return array<string, array{array<string,string>, bool}> */
    public static function meaningfulFixtures(): array
    {
        return [
            'a display name'      => [['display_name' => 'Uriel Papa'], true],
            'nothing at all'      => [[], false],
            'empty display name'  => [['display_name' => ''], false],
            'spaces only'         => [['display_name' => '   '], false],
            // A headline without a name has nothing to head. The page needs a
            // name, which is why isMeaningful() reads display_name alone.
            'headline but no name' => [['headline' => 'CEO, Vite SEO'], false],
            'tagline but no name'  => [['tagline' => 'Say my name'], false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('meaningfulFixtures')]
    public function test_is_meaningful_requires_a_display_name(array $attributes, bool $expected): void
    {
        $profile = new NfcProfile($attributes);

        $this->assertSame($expected, $profile->isMeaningful());
    }

    /**
     * The same fixtures drive the PHP method and the SQL scope. Two
     * implementations of one rule drift silently otherwise: the dashboard would
     * report a card as blank while its public page is live.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('meaningfulFixtures')]
    public function test_the_meaningful_scope_agrees_with_is_meaningful(array $attributes, bool $expected): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make($attributes));
        $profile = $card->fresh()->profile;

        $this->assertSame($expected, $profile->isMeaningful(), 'PHP method');

        $this->assertSame(
            $expected,
            NfcProfile::query()->meaningful()->whereKey($profile->getKey())->exists(),
            'the SQL scope and the PHP method must not disagree'
        );
    }

    public function test_the_meaningful_scope_partitions_the_table(): void
    {
        foreach ([['display_name' => 'Uriel Papa'], ['display_name' => ''], ['display_name' => '  '], []] as $attributes) {
            $card = $this->card();
            $card->profile()->save($card->profile()->make($attributes));
        }

        $meaningful = NfcProfile::query()->meaningful()->count();
        $total      = NfcProfile::query()->count();

        $this->assertSame(1, $meaningful);
        $this->assertSame(4, $total, 'every fixture was stored');
    }

    public function test_card_id_avatar_path_and_brand_color_are_not_mass_assignable(): void
    {
        $card  = $this->card();
        $other = $this->card();

        $profile = $card->profile()->make([]);
        $profile->fill([
            'display_name' => 'Uriel Papa',
            'card_id'      => $other->id,
            'avatar_path'  => '../../etc/passwd',
            'brand_color'  => '#ff0000',
        ]);

        $this->assertSame($card->id, $profile->card_id, 'card_id must not be mass-assignable from request data');
        $this->assertNull($profile->avatar_path, 'avatar_path must not be mass-assignable from request data');
        $this->assertNull($profile->brand_color, 'brand_color must not be mass-assignable from request data');

        $card->profile()->save($profile);

        $stored = $card->fresh()->profile;

        $this->assertNull($stored->avatar_path, 'avatar_path is set only from a server-generated name');
        $this->assertNull($stored->brand_color, 'brand_color is derived, never submitted');
    }

    public function test_default_brand_color_constant(): void
    {
        $this->assertSame('#0f1713', NfcProfile::DEFAULT_BRAND_COLOR);
    }

    public function test_a_card_may_have_only_one_profile(): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa']));

        $this->expectException(QueryException::class);

        $card->profile()->save($card->profile()->make(['display_name' => 'Duplicate']));
    }

    public function test_deleting_a_card_deletes_its_profile(): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa']));

        $card->delete();

        $this->assertSame(0, NfcProfile::query()->count());
    }

    public function test_a_profile_mode_card_with_no_profile_shows_the_not_ready_page(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        // Deliberately NOT the links setup form: Profile mode is admin-managed,
        // so a visitor must never be offered a form that writes links onto it.
        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertDontSee('activation_pin', false);
    }

    public function test_a_profile_mode_card_with_a_nameless_profile_shows_the_not_ready_page(): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(['headline' => 'CEO, Vite SEO']));
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertDontSee('activation_pin', false)
            ->assertDontSee('CEO, Vite SEO');
    }

    public function test_public_self_service_setup_still_refuses_profile_mode(): void
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        $this->post('/n/'.$card->public_id, [
            'activation_pin' => '000000',
            'links'          => [['title' => 'Site', 'url' => 'https://example.com']],
        ])->assertRedirect('/n/'.$card->public_id);

        $this->assertSame(0, $card->nfcLinks()->count(), 'a visitor must not be able to write links onto a profile card');
    }

    public function test_the_profile_mode_page_carries_no_store_and_noindex_headers(): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa']));
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        $response = $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Symfony normalises and alphabetises Cache-Control, so assert on semantics.
        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('private', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('no-cache', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('must-revalidate', $cc, "Cache-Control was: {$cc}");
        $this->assertStringContainsString('max-age=0', $cc, "Cache-Control was: {$cc}");
    }

    private function profileCard(array $attributes = []): Card
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(array_merge(['display_name' => 'Uriel Papa'], $attributes)));
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        return $card->fresh();
    }

    public function test_the_profile_page_shows_the_identity_block(): void
    {
        $card = $this->profileCard([
            'headline' => 'Chief Executive Officer, Vite SEO Digital Advertising OPC',
            'username' => 'yurielyel',
            'location' => 'Philippines',
            'tagline'  => 'Say my name',
        ]);

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Uriel Papa')
            ->assertSee('Chief Executive Officer, Vite SEO Digital Advertising OPC')
            ->assertSee('yurielyel')
            ->assertSee('Philippines')
            ->assertSee('Say my name');
    }

    public function test_an_empty_optional_field_renders_nothing_rather_than_an_empty_element(): void
    {
        $card = $this->profileCard();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('class="headline"', $body);
        $this->assertStringNotContainsString('class="meta"', $body);
        $this->assertStringNotContainsString('class="tagline"', $body);
    }

    public function test_the_avatar_falls_back_to_initials_when_there_is_no_photo(): void
    {
        $card = $this->profileCard();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('UP')
            ->assertDontSee('<img', false);
    }

    public function test_the_avatar_renders_the_stored_photo_when_there_is_one(): void
    {
        $card = $this->profileCard();
        $card->profile->forceFill(['avatar_path' => 'profile-avatars/a.jpg'])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('storage/profile-avatars/a.jpg', false);
    }

    /** @return array<string, array{string, string}> */
    public static function initialsFixtures(): array
    {
        return [
            'two words'            => ['Uriel Papa', 'UP'],
            'one word'             => ['Uriel', 'U'],
            'three words'          => ['Uriel Q Papa', 'UP'],
            'lowercase'            => ['uriel papa', 'UP'],
            'extra spaces'         => ['  Uriel   Papa  ', 'UP'],
            'lowercase non-ascii'  => ['ñigo pérez', 'ÑP'],
            'mixed non-ascii'      => ['Ñigo cruz', 'ÑC'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('initialsFixtures')]
    public function test_initials_come_from_the_first_and_last_word(string $name, string $expected): void
    {
        $this->assertSame($expected, (new NfcProfile(['display_name' => $name]))->initials());
    }

    public function test_every_profile_field_is_escaped_on_the_page(): void
    {
        $card = $this->profileCard([
            'display_name' => '<script>alert(1)</script>',
            'headline'     => '<img src=x onerror=alert(2)>',
            'username'     => '"><b>u</b>',
            'location'     => '<svg onload=alert(3)>',
            'tagline'      => '<iframe src=javascript:alert(4)>',
        ]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $body);
        $this->assertStringNotContainsString('"><b>u</b>', $body);
        $this->assertStringNotContainsString('<svg onload', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function test_advanced_details_shows_the_permanent_url_and_never_the_card_number(): void
    {
        $card = $this->profileCard();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString($card->public_id, $body);
        $this->assertStringContainsString($card->profile->updated_at->format('j F Y'), $body);
        $this->assertStringNotContainsString(
            $card->card_number,
            $body,
            'the card number tells a visitor how many cards exist and where theirs falls'
        );
    }

    public function test_advanced_details_opens_a_centred_modal_instead_of_an_inline_disclosure(): void
    {
        $card = $this->profileCard();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('class="adv-trigger" href="#advanced-details"', $body);
        $this->assertStringContainsString(
            'class="modal" id="advanced-details" role="dialog" aria-modal="true" aria-labelledby="advanced-details-title"',
            $body
        );
        $this->assertStringContainsString('href="#" aria-label="Close"', $body);
        $this->assertStringContainsString('.modal:target{display:flex}', $body);
        $this->assertMatchesRegularExpression(
            '#\.modal\{[^}]*align-items:center;[^}]*justify-content:center;#s',
            $body,
            'the overlay is not centred in both directions'
        );
        $this->assertStringContainsString('max-height:calc(100dvh - 48px)', $body);
        $this->assertStringNotContainsString('<details class="adv">', $body);
    }

    /**
     * The trigger is `display:inline-flex; align-items:center; gap:8px` - a
     * two-child layout. With only the label in it the gap has nothing to space
     * and the pill reads as a plain word rather than a control, which is why
     * the mockup puts a chevron there and why the CSS for it is already in the
     * stylesheet.
     */
    public function test_the_advanced_details_trigger_carries_the_chevron(): void
    {
        $card = $this->profileCard();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<a class="adv-trigger" href="#advanced-details"[^>]*>\s*<svg[^>]*>.*?</svg>\s*Advanced details\s*</a>~s',
            $body,
            'the modal trigger has no mark before its label'
        );
    }

    /**
     * Asserted as path data, not just as "an svg": mistyped path data renders
     * as a smear that no structural assertion would catch. These two paths are
     * the mockup's, verbatim.
     */
    public function test_the_chevron_is_the_mockups_own_mark(): void
    {
        $card = $this->profileCard();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('<path d="M8 6 3 12l5 6"/><path d="m16 6 5 6-5 6"/>', false);
    }

    public function test_the_advanced_details_modal_renders_and_escapes_the_welcome_panel(): void
    {
        $card = $this->profileCard([
            'welcome_eyebrow'   => '<script>eyebrow()</script>',
            'welcome_heading'   => '<img src=x onerror=heading()>',
            'welcome_body'      => '<svg onload=body()>Body',
            'welcome_cta_label' => 'Book <b>now</b>',
            'welcome_cta_url'   => 'https://viteseo.ph/book',
        ]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('class="welcome"', $body);
        $this->assertStringContainsString('&lt;script&gt;eyebrow()&lt;/script&gt;', $body);
        $this->assertStringContainsString('&lt;img src=x onerror=heading()&gt;', $body);
        $this->assertStringContainsString('&lt;svg onload=body()&gt;Body', $body);
        $this->assertStringContainsString('>Book &lt;b&gt;now&lt;/b&gt;</a>', $body);
        $this->assertStringNotContainsString('<script>eyebrow()</script>', $body);
        $this->assertStringNotContainsString('<img src=x onerror=heading()>', $body);
        $this->assertStringNotContainsString('<svg onload=body()>', $body);
        $this->assertStringNotContainsString('>Book <b>now</b></a>', $body);
    }

    public function test_an_empty_welcome_panel_is_omitted_from_advanced_details(): void
    {
        $card = $this->profileCard();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertDontSee('class="welcome"', false);
    }

    public function test_a_safe_welcome_cta_is_a_live_external_link(): void
    {
        $card = $this->profileCard([
            'welcome_heading'   => 'Grow your brand',
            'welcome_cta_label' => 'Book now',
            'welcome_cta_url'   => 'https://viteseo.ph/book',
        ]);

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee(
                'class="welcome-cta" href="https://viteseo.ph/book" rel="noopener noreferrer nofollow ugc" target="_blank"',
                false
            );
    }

    public function test_an_unsafe_welcome_cta_never_becomes_a_live_link(): void
    {
        $card = $this->profileCard([
            'welcome_heading'   => 'Grow your brand',
            'welcome_cta_label' => 'Book now',
            'welcome_cta_url'   => 'javascript:alert(document.domain)',
        ]);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('href="javascript:', $body);
        $this->assertStringNotContainsString('class="welcome-cta"', $body);
    }

    public function test_the_page_uses_the_default_brand_colour_when_none_is_derived(): void
    {
        $card = $this->profileCard();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('--brand:'.NfcProfile::DEFAULT_BRAND_COLOR, false);
    }

    public function test_a_derived_brand_colour_is_used_for_the_page_and_the_cover(): void
    {
        $card = $this->profileCard();
        $card->profile->forceFill(['brand_color' => '#1d5b45'])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('--brand:#1d5b45', false)
            ->assertSee('--brand-soft:rgba(29,91,69,.34)', false);
    }

    /** @return array<string, array{string}> */
    public static function malformedBrandColours(): array
    {
        return [
            'style break out'  => ['red;}</style><script>alert(1)</script>'],
            'no hash'          => ['1d5b45'],
            'short form'       => ['#abc'],
            'not hex'          => ['#zzzzzz'],
            'empty'            => [''],
        ];
    }

    /**
     * brand_color is interpolated into a style attribute, so the value that
     * reaches the page must be a hex colour or nothing. It is not mass
     * assignable, which makes this defence in depth rather than the only guard
     * — but a derived column written by a service is exactly the kind of value
     * that gets a second writer later.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBrandColours')]
    public function test_a_malformed_brand_colour_falls_back_to_the_default(string $stored): void
    {
        $card = $this->profileCard();
        $card->profile->forceFill(['brand_color' => $stored])->save();

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('--brand:'.NfcProfile::DEFAULT_BRAND_COLOR, false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // --- the accounts panel and the icon row ---------------------------------

    private function link(Card $card, string $url, string $title = 'Link', bool $active = true, int $order = 0): void
    {
        $card->nfcLinks()->create([
            'title' => $title, 'url' => $url, 'sort_order' => $order, 'is_active' => $active,
        ]);
    }

    /**
     * The icon row is one of two blocks whose boundary a test has to find,
     * because "which marks are in it" is the whole rule. Everything inside it
     * is an <a> and an <svg>, so the first closing div is its own — and this
     * helper asserts exactly that, so the day someone nests a div here the
     * assumption fails loudly instead of silently widening the match.
     */
    private function iconRow(string $body): string
    {
        $this->assertSame(
            1,
            preg_match_all('#<div class="iconrow">(.*?)</div>#s', $body, $m),
            'expected exactly one icon row'
        );
        $this->assertStringNotContainsString(
            '<div',
            $m[1][0],
            'the icon row must not nest a div, or the first </div> is no longer its own'
        );

        return $m[1][0];
    }

    /**
     * Bounded at the panel's own </section>, not at the end of the document:
     * a helper that ran on to the footer would let a mark or a URL rendered
     * anywhere below the panel satisfy an assertion about the panel.
     */
    private function accountsPanel(string $body): string
    {
        $this->assertSame(
            1,
            preg_match_all('#<h2>Accounts</h2>(.*?)</section>#s', $body, $m),
            'expected exactly one accounts panel'
        );
        $this->assertStringNotContainsString(
            '<section',
            $m[1][0],
            'the panel must not nest a section, or the first </section> is no longer its own'
        );

        return $m[1][0];
    }

    /**
     * The exact <svg> the component renders for a platform.
     *
     * Comparing against the component's own output rather than against pasted
     * path data is what lets a test say "this row wears the X mark" instead of
     * the far weaker "this row wears some mark".
     */
    private function mark(string $platform): string
    {
        $html = Blade::render('<x-platform-icon :platform="$platform" />', ['platform' => $platform]);

        $this->assertSame(1, preg_match('#<svg.*</svg>#s', $html, $m), "no mark rendered for {$platform}");

        return $m[0];
    }

    public function test_the_accounts_panel_lists_each_active_link_with_its_platform_and_display_url(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/its_uriel26', 'X', true, 0);
        $this->link($card, 'https://www.linkedin.com/in/uriel-papa/', 'LinkedIn', true, 1);

        $panel = $this->accountsPanel($this->get('/n/'.$card->public_id)->assertOk()->getContent());

        $this->assertStringContainsString('<div class="acct-name">X</div>', $panel);
        $this->assertStringContainsString('<div class="acct-name">LinkedIn</div>', $panel);

        // Asserted as the anchor's inner text, not as a bare substring: the
        // href holds the same characters, so a plain assertSee() would pass
        // with the visible line rendered empty.
        $this->assertStringContainsString('>x.com/its_uriel26</a>', $panel);
        $this->assertStringContainsString('>linkedin.com/in/uriel-papa</a>', $panel);
    }

    /**
     * One mark per row. Without a count the panel could ship every row as a
     * bare label and nothing would notice.
     */
    public function test_every_account_row_carries_a_mark(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/a', 'X', true, 0);
        $this->link($card, 'https://github.com/b', 'GitHub', true, 1);
        $this->link($card, 'https://viteseo.ph/c', 'Site', true, 2);

        $panel = $this->accountsPanel($this->get('/n/'.$card->public_id)->assertOk()->getContent());

        $this->assertSame(3, substr_count($panel, '<span class="acct-ico">'), 'one icon slot per row');
        $this->assertSame(3, substr_count($panel, '<svg'), 'one mark per row');
        $this->assertSame(3, substr_count($panel, 'class="acct-url"'), 'one visible URL per row');
    }

    /**
     * The mark has to be the link's own. A single hardcoded platform would
     * dress every row in the same globe while the label beside it still read
     * "X" — which is worse than no icon, because the icon is the part a
     * visitor reads first.
     */
    public function test_each_list_renders_the_mark_belonging_to_the_link(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/its_uriel26', 'X', true, 0);
        // Two distinct brands, not one. With a single brand in the fixture any
        // assertion is satisfied by hardcoding that same platform for every
        // mark; two mean no single hardcoded value can pass.
        $this->link($card, 'https://github.com/urielpapa', 'GitHub', true, 1);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        foreach (['icon row' => $this->iconRow($body), 'accounts panel' => $this->accountsPanel($body)] as $where => $html) {
            $this->assertStringContainsString($this->mark('x'), $html, "the {$where} does not wear the X mark");
            $this->assertStringContainsString($this->mark('github'), $html, "the {$where} does not wear the GitHub mark");
            $this->assertStringNotContainsString($this->mark('web'), $html, "the {$where} fell back to the globe");
        }
    }

    public function test_an_inactive_link_is_not_listed(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/shown', 'X', true, 0);
        $this->link($card, 'https://x.com/hidden', 'X', false, 1);

        $body  = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $panel = $this->accountsPanel($body);

        $this->assertStringContainsString('>x.com/shown</a>', $panel);
        $this->assertStringNotContainsString('x.com/hidden', $body);
    }

    public function test_the_accounts_panel_is_omitted_entirely_when_there_are_no_links(): void
    {
        $card = $this->profileCard();

        $this->get('/n/'.$card->public_id)->assertOk()->assertDontSee('Accounts');
    }

    public function test_an_unrecognised_host_is_listed_as_a_website_with_the_globe(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://viteseo.ph/services');

        $panel = $this->accountsPanel($this->get('/n/'.$card->public_id)->assertOk()->getContent());

        $this->assertStringContainsString('<div class="acct-name">Website</div>', $panel);
        $this->assertStringContainsString('>viteseo.ph/services</a>', $panel);
        $this->assertStringContainsString($this->mark('web'), $panel, 'the globe is the whole point of this row');
    }

    /**
     * The icon is the only signal a visitor has about where a row leads, so a
     * host that merely contains a brand name must not wear that brand's mark.
     * LinkPlatform has its own unit test for this; this one pins that the view
     * actually routes through it — label and mark both.
     */
    public function test_a_spoofed_host_does_not_get_a_brand_icon_on_the_page(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://linkedin.com.attacker.tld/in/uriel');

        $body  = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $panel = $this->accountsPanel($body);

        $this->assertStringContainsString('<div class="acct-name">Website</div>', $panel);
        $this->assertStringContainsString($this->mark('web'), $panel);
        $this->assertStringNotContainsString($this->mark('linkedin'), $body, 'a hostile host must not borrow the LinkedIn mark');
        $this->assertStringNotContainsString('LinkedIn', $body, 'a hostile host must not borrow the LinkedIn label');
    }

    /**
     * The panel's most-read line names the host the visitor will actually
     * reach. Userinfo before an @ is the classic way to make that line open
     * with a brand the visitor trusts.
     */
    public function test_a_userinfo_url_does_not_read_as_the_brand_it_borrows(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com@attacker.tld/pay');

        $panel = $this->accountsPanel($this->get('/n/'.$card->public_id)->assertOk()->getContent());

        $this->assertStringContainsString('>attacker.tld/pay</a>', $panel);
        $this->assertStringNotContainsString('>x.com@attacker.tld/pay</a>', $panel);
    }

    public function test_the_icon_row_carries_brand_links_only(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/its_uriel26', 'X', true, 0);
        $this->link($card, 'https://viteseo.ph/services', 'Site', true, 1);

        $body  = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $panel = $this->accountsPanel($body);

        // One mark in the icon row (X); the plain website appears only in the
        // panel below. A row of identical globes is noise, not navigation.
        $this->assertSame(1, substr_count($this->iconRow($body), '<svg'), 'the icon row must carry brand marks only');

        // Both links are still reachable from the panel, as visible text.
        $this->assertStringContainsString('>x.com/its_uriel26</a>', $panel);
        $this->assertStringContainsString('>viteseo.ph/services</a>', $panel);
    }

    public function test_the_icon_row_is_omitted_when_no_link_has_a_brand(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://viteseo.ph/services');

        $this->assertStringNotContainsString(
            'class="iconrow"',
            $this->get('/n/'.$card->public_id)->assertOk()->getContent(),
            'an empty icon row is a gap under the name, not navigation'
        );
    }

    /**
     * Both lists read the same ordered relation, so the order an administrator
     * arranged is the order a visitor sees. Insertion order here is deliberately
     * not sort order: a list that merely echoed the ids would pass otherwise.
     */
    public function test_both_lists_follow_sort_order(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://github.com/third', 'GitHub', true, 2);
        $this->link($card, 'https://x.com/first', 'X', true, 0);
        $this->link($card, 'https://www.linkedin.com/in/second', 'LinkedIn', true, 1);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        preg_match_all('#aria-label="([^"]+)"#', $this->iconRow($body), $marks);
        $this->assertSame(['X', 'LinkedIn', 'GitHub'], $marks[1], 'the icon row ignores sort_order');

        preg_match_all('#<div class="acct-name">([^<]+)</div>#', $this->accountsPanel($body), $names);
        $this->assertSame(['X', 'LinkedIn', 'GitHub'], $names[1], 'the accounts panel ignores sort_order');
    }

    public function test_a_link_url_is_escaped_in_the_href(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://example.com/"><script>alert(1)</script>');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body, 'the URL reached the page, escaped');
    }

    /**
     * Escaping the attribute is not a scheme check. SafeExternalUrl guards
     * every write path today, so a "javascript:" row can only exist if some
     * future path forgets it — which is exactly when a read-side backstop is
     * the thing standing between a stored string and script execution.
     */
    public function test_a_stored_javascript_url_never_becomes_a_live_href(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'javascript:alert(document.domain)');

        $body  = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $panel = $this->accountsPanel($body);

        $this->assertStringNotContainsString('href="javascript:', $body);
        $this->assertStringContainsString('class="acct-dead"', $panel, 'the row stays, defused');
    }

    public function test_a_stored_data_url_never_becomes_a_live_href(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('href="data:', $body);
        $this->assertStringContainsString('class="acct-dead"', $this->accountsPanel($body));
    }

    /**
     * A defused row must not be an anchor at all.
     *
     * href="#" resolves to the current document, so target="_blank" would open
     * a second copy of this very profile page — a link that looks like it
     * worked. The row keeps its data (dropping an administrator's row silently
     * is worse) but stops pretending to lead anywhere.
     */
    public function test_a_defused_row_is_not_a_link_at_all(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'javascript:alert(1)');

        $panel = $this->accountsPanel($this->get('/n/'.$card->public_id)->assertOk()->getContent());

        $this->assertStringContainsString('class="acct-dead"', $panel);
        $this->assertStringNotContainsString('<a', $panel, 'a dead destination must not render as an anchor');
        $this->assertStringNotContainsString('href=', $panel);
        $this->assertStringNotContainsString('target="_blank"', $panel, 'href="#" plus _blank opens a duplicate tab');
    }

    /**
     * parse_url reports a host of x.com for "javascript://x.com/...", so
     * without a scheme check the row wears the X mark and the X label while
     * leading nowhere. safeHref defuses the href, but the borrowed brand is
     * the whole trust signal.
     */
    public function test_a_hostile_scheme_row_does_not_borrow_the_brand_in_its_host(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'javascript://x.com/%0aalert(1)');

        $body  = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $panel = $this->accountsPanel($body);

        $this->assertStringContainsString('<div class="acct-name">Website</div>', $panel);
        $this->assertStringNotContainsString('<div class="acct-name">X</div>', $panel);
        $this->assertStringNotContainsString($this->mark('x'), $body, 'a hostile scheme must not wear the X mark');
        $this->assertStringContainsString($this->mark('web'), $panel);
    }

    /**
     * Browsers treat "\" as "/" after a special scheme, so this navigates to
     * evil.tld. parse_url reports a scheme but no host, which is why safeHref
     * requires both.
     */
    public function test_a_backslash_authority_is_defused_rather_than_live(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https:/\/\x.com@evil.tld/pay');

        $body  = $this->get('/n/'.$card->public_id)->assertOk()->getContent();
        $panel = $this->accountsPanel($body);

        $this->assertStringNotContainsString('href=', $panel, 'the tap must not be live');
        $this->assertStringContainsString('class="acct-dead"', $panel);
        $this->assertStringNotContainsString($this->mark('x'), $body);
    }

    /**
     * The icon row is bare marks with no text, so a mark that goes nowhere is
     * a button that lies with no room to explain itself. Nothing defused can
     * reach the row because every defused URL classifies as `web` and the row
     * carries no `web` links — this pins that consequence, so a change to
     * either rule is caught here rather than shipping a dead mark.
     */
    public function test_the_icon_row_never_carries_a_defused_link(): void
    {
        $card = $this->profileCard();

        foreach (['javascript://x.com/a', 'data://github.com/b', '//x.com/c', 'https:/\/\x.com@evil.tld/pay'] as $i => $url) {
            $this->link($card, $url, 'L', true, $i);
        }

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('class="iconrow"', $body, 'a mark that goes nowhere is a button that lies');
        $this->assertStringNotContainsString($this->mark('x'), $body);
        $this->assertStringNotContainsString($this->mark('github'), $body);
        $this->assertSame(4, substr_count($this->accountsPanel($body), 'class="acct-dead"'), 'every row is listed, all inert');
    }

    /**
     * parse_url mangles a non-ASCII host into invalid UTF-8, and Blade renders
     * invalid UTF-8 as nothing at all — an icon and a label with no URL beside
     * them. Unreachable while SafeExternalUrl restricts hosts to ASCII, but it
     * is a trap laid for whichever increment adds IDN support.
     */
    public function test_a_non_ascii_host_still_renders_a_visible_url(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://х.com/pay');

        $panel = $this->accountsPanel($this->get('/n/'.$card->public_id)->assertOk()->getContent());

        $this->assertStringContainsString('>https://х.com/pay</a>', $panel, 'the row lost its visible URL');
    }

    public function test_an_ordinary_link_still_reaches_its_href_untouched(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/its_uriel26');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        // The backstop must not become a wall: both lists still link out.
        $this->assertStringContainsString('href="https://x.com/its_uriel26"', $this->iconRow($body));
        $this->assertStringContainsString('href="https://x.com/its_uriel26"', $this->accountsPanel($body));
    }
}

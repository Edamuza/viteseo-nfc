<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\NfcProfile;
use App\Services\CardGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'two words'    => ['Uriel Papa', 'UP'],
            'one word'     => ['Uriel', 'U'],
            'three words'  => ['Uriel Q Papa', 'UP'],
            'lowercase'    => ['uriel papa', 'UP'],
            'extra spaces' => ['  Uriel   Papa  ', 'UP'],
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
        $this->assertStringNotContainsString('<svg onload', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function test_advanced_details_shows_the_permanent_url_and_never_the_card_number(): void
    {
        $card = $this->profileCard();

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString($card->public_id, $body);
        $this->assertStringNotContainsString(
            $card->card_number,
            $body,
            'the card number tells a visitor how many cards exist and where theirs falls'
        );
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
}

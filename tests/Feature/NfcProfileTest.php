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
}

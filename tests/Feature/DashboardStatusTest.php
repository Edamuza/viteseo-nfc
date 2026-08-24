<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\DashboardController;
use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\NfcContactProfile;
use App\Models\User;
use App\Services\CardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardStatusTest extends TestCase
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

    private function withLinkCount(Card $card): Card
    {
        return Card::query()
            ->select('cards.*')
            ->addSelect(DB::raw(
                '(select count(*) from nfc_links where nfc_links.card_id = cards.id and nfc_links.is_active = 1) as active_link_count'
            ))
            ->findOrFail($card->id);
    }

    public function test_preloaded_active_link_count_is_used_without_another_query(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create([
            'title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true,
        ]);
        $card->nfcLinks()->create([
            'title' => 'B', 'url' => 'https://example.com/b', 'sort_order' => 1, 'is_active' => false,
        ]);

        $loaded = $this->withLinkCount($card);

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $this->assertSame(1, $loaded->activeNfcLinkCount(), 'only the active link counts');
        $this->assertSame(0, $queries, 'the preloaded aggregate must not trigger another query');
    }

    public function test_active_link_count_still_queries_when_not_preloaded(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create([
            'title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true,
        ]);

        $this->assertSame(1, Card::findOrFail($card->id)->activeNfcLinkCount());
    }

    /** @return array<string, array{array<string,string>, bool}> */
    public static function contactFixtures(): array
    {
        return [
            'first name only'   => [['first_name' => 'John'], true],
            'middle name only'  => [['middle_name' => 'Quincy'], true],
            'last name only'    => [['last_name' => 'Doe'], true],
            'suffix only'       => [['suffix' => 'Jr'], true],
            'company only'      => [['company' => 'Acme Ltd'], true],
            'nothing at all'    => [[], false],
            'blank strings'     => [['first_name' => '', 'company' => ''], false],
            'job title only'    => [['job_title' => 'Director'], false],
            'phone only'        => [['mobile_phone' => '+639171234567'], false],
            'whitespace first name only' => [['first_name' => '   '], false],
            'whitespace in all name parts' => [[
                'first_name' => ' ', 'middle_name' => ' ', 'last_name' => ' ', 'suffix' => ' ',
            ], false],
            // Inherited from hasName(): company is not trimmed, so a whitespace-only
            // company is non-empty and isMeaningful() returns true.
            'whitespace company only' => [['company' => ' '], true],
            'whitespace first name with real company' => [['first_name' => '   ', 'company' => 'Acme Ltd'], true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contactFixtures')]
    public function test_meaningful_scope_agrees_with_is_meaningful(array $attrs, bool $expected): void
    {
        $card = $this->card();
        $profile = $card->contactProfile()->make($attrs);
        $card->contactProfile()->save($profile);

        $this->assertSame($expected, $profile->isMeaningful(), 'PHP method');

        $this->assertSame(
            $expected,
            NfcContactProfile::query()->meaningful()->whereKey($profile->getKey())->exists(),
            'the SQL scope and the PHP method must not disagree'
        );
    }

    /**
     * Builds a card in the given mode, either configured for that mode or not.
     * Every mode's "configured" shape is different, which is the whole reason
     * the dashboard could not derive status from link count alone.
     *
     * A configured card also gets a QR destination, so that later tests can
     * distinguish "this row's NFC is unconfigured" from "this row's QR is".
     */
    private function cardInMode(string $mode, bool $configured): Card
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => $mode])->save();

        if (! $configured) {
            return $card->fresh();
        }

        match ($mode) {
            'links' => $card->nfcLinks()->create([
                'title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true,
            ]),
            'contact' => $card->contactProfile()->save($card->contactProfile()->make(['first_name' => 'John'])),
            'phone' => $card->forceFill(['nfc_phone_number' => '+639171234567'])->save(),
            'email' => $card->forceFill(['nfc_email_address' => 'hi@example.com'])->save(),
        };

        $card->forceFill([
            'qr_destination'   => 'https://example.com/qr',
            'qr_configured_at' => now(),
        ])->save();

        return $card->fresh();
    }

    /** @return array<string, array{string, bool}> */
    public static function modeStates(): array
    {
        return [
            'links configured'      => ['links', true],
            'links unconfigured'    => ['links', false],
            'contact configured'    => ['contact', true],
            'contact unconfigured'  => ['contact', false],
            'phone configured'      => ['phone', true],
            'phone unconfigured'    => ['phone', false],
            'email configured'      => ['email', true],
            'email unconfigured'    => ['email', false],
        ];
    }

    /**
     * modeStates() is the fixture set behind the agreement test and the badge
     * matrix, and it is written out by hand. A fifth mode added to the enum
     * without being added here would inherit both guards silently: the SQL twin
     * could disagree with the PHP method, and the dashboard could mislabel it,
     * with every test still green. So the list is pinned to the enum itself.
     */
    public function test_the_mode_fixtures_cover_every_mode_in_the_enum(): void
    {
        $covered = array_unique(array_map(fn (array $case) => $case[0], self::modeStates()));
        sort($covered);

        $modes = array_map(fn (NfcMode $m) => $m->value, NfcMode::cases());
        sort($modes);

        $this->assertSame(
            $modes,
            $covered,
            'a mode exists that modeStates() does not cover: extend the fixtures, the SQL twin and cardInMode() together'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeStates')]
    public function test_configured_scope_agrees_with_nfc_mode_is_configured(string $mode, bool $expected): void
    {
        $card = $this->cardInMode($mode, $expected);

        $this->assertSame($expected, $card->nfcModeIsConfigured(), 'PHP method');

        $this->assertSame(
            $expected,
            Card::query()->whereNfcModeConfigured()->whereKey($card->getKey())->exists(),
            'the SQL scope and the PHP method must not disagree'
        );

        $this->assertSame(
            ! $expected,
            Card::query()->whereNfcModeConfigured(false)->whereKey($card->getKey())->exists(),
            'the inverted scope must be the exact complement'
        );
    }

    public function test_an_inactive_link_does_not_configure_links_mode(): void
    {
        $card = $this->card();
        $card->nfcLinks()->create([
            'title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => false,
        ]);

        $this->assertFalse($card->fresh()->nfcModeIsConfigured());
        $this->assertFalse(Card::query()->whereNfcModeConfigured()->whereKey($card->getKey())->exists());
    }

    /** @return array<string, array{string, string}> */
    public static function driftGuardFixtures(): array
    {
        return [
            'active link on contact mode' => ['contact', 'active_link'],
            'phone number on email mode' => ['email', 'phone'],
            'email address on links mode' => ['links', 'email'],
            'contact profile on phone mode' => ['phone', 'contact'],
            'phone mode with empty phone number' => ['phone', 'empty_phone'],
            'email mode with empty email address' => ['email', 'empty_email'],
        ];
    }

    private function cardForDriftGuardFixture(string $mode, string $payload): Card
    {
        $card = $this->card();
        $card->forceFill(['nfc_mode' => $mode])->save();

        match ($payload) {
            'active_link' => $card->nfcLinks()->create([
                'title' => 'A', 'url' => 'https://example.com/a', 'sort_order' => 0, 'is_active' => true,
            ]),
            'contact' => $card->contactProfile()->save(
                $card->contactProfile()->make(['first_name' => 'John'])
            ),
            'phone' => $card->forceFill(['nfc_phone_number' => '+639171234567'])->save(),
            'email' => $card->forceFill(['nfc_email_address' => 'hi@example.com'])->save(),
            'empty_phone' => $card->forceFill(['nfc_phone_number' => ''])->save(),
            'empty_email' => $card->forceFill(['nfc_email_address' => ''])->save(),
        };

        return $card->fresh();
    }

    private function assertConfiguredScopeAgreement(Card $card, bool $expected): void
    {
        $this->assertSame($expected, $card->nfcModeIsConfigured(), 'PHP method');

        $this->assertSame(
            $expected,
            Card::query()->whereNfcModeConfigured()->whereKey($card->getKey())->exists(),
            'the SQL scope and the PHP method must not disagree'
        );

        $this->assertSame(
            ! $expected,
            Card::query()->whereNfcModeConfigured(false)->whereKey($card->getKey())->exists(),
            'the inverted scope must be the exact complement'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('driftGuardFixtures')]
    public function test_drift_guards_keep_php_and_sql_agreement(string $mode, string $payload): void
    {
        $card = $this->cardForDriftGuardFixture($mode, $payload);

        $this->assertConfiguredScopeAgreement($card, false);
    }

    /**
     * The badge renders as "{mode label} &middot; {Active|Unconfigured}" on a
     * single line, so asserting the whole string pins mode and state together.
     *
     * Do NOT assert with assertDontSee('Unconfigured'): the stats block at the
     * top of the dashboard has a permanent stat labelled "Unconfigured", so
     * that assertion can never pass. Assert the exact badge instead.
     */
    private function assertNfcBadge(string $expected): void
    {
        $body = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString($expected, $body);
    }

    /**
     * Every mode in both states, so neither half of the badge can be wrong
     * while the suite stays green: a hard-coded label is caught by the other
     * three modes, and a state stuck on Active is caught by the unconfigured
     * half of each pair.
     *
     * @param  'links'|'contact'|'phone'|'email'  $mode
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modeStates')]
    public function test_dashboard_badge_names_the_mode_and_its_state(string $mode, bool $configured): void
    {
        $card = $this->cardInMode($mode, $configured);

        $this->assertNfcBadge(
            $card->nfcMode()->label().' &middot; '.($configured ? 'Active' : 'Unconfigured')
        );
    }

    /** The NFC Links cell's text, whatever attribute order the markup uses. */
    private function linkCountCell(): string
    {
        $body = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<td[^>]*data-label="NFC Links"[^>]*>(.*?)<\/td>/s', $body);
        preg_match('/<td[^>]*data-label="NFC Links"[^>]*>(.*?)<\/td>/s', $body, $m);

        return trim($m[1]);
    }

    public function test_dashboard_link_count_column_shows_an_em_dash_outside_links_mode(): void
    {
        $this->cardInMode('contact', true);

        // A literal 0 next to a live contact card is what made the old
        // dashboard read as broken: the count means nothing outside Links mode.
        $this->assertSame('—', $this->linkCountCell());
    }

    public function test_dashboard_link_count_column_still_counts_in_links_mode(): void
    {
        $this->cardInMode('links', true);

        $this->assertSame('1', $this->linkCountCell());
    }

    private function cardWithQrDestination(?string $destination): Card
    {
        $card = $this->card();
        $card->forceFill(['qr_destination' => $destination])->save();

        return $card->fresh();
    }

    /** @return array<string, array{?string, bool}> */
    public static function qrDestinationFixtures(): array
    {
        return [
            'null destination'  => [null, false],
            'empty string'      => ['', false],
            'real url'          => ['https://example.com/qr', true],
            // qrIsConfigured() compares against '' without trimming, so a
            // whitespace-only destination counts as configured. The SQL twin
            // must not trim either.
            'whitespace only'   => [' ', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('qrDestinationFixtures')]
    public function test_qr_configured_scope_agrees_with_qr_is_configured(?string $destination, bool $expected): void
    {
        $card = $this->cardWithQrDestination($destination);

        $this->assertSame($expected, $card->qrIsConfigured(), 'PHP method');

        $this->assertSame(
            $expected,
            Card::query()->whereQrConfigured()->whereKey($card->getKey())->exists(),
            'the SQL scope and the PHP method must not disagree'
        );

        $this->assertSame(
            ! $expected,
            Card::query()->whereQrConfigured(false)->whereKey($card->getKey())->exists(),
            'the inverted scope must be the exact complement'
        );
    }

    /**
     * A nullable column is where a naive negation loses rows: in SQL
     * NULL != '' is UNKNOWN, so the NULL card would fall out of both branches
     * and the two counts would no longer add up to the table.
     */
    public function test_qr_configured_scope_and_its_complement_partition_the_table(): void
    {
        $this->cardWithQrDestination(null);
        $this->cardWithQrDestination('');
        $this->cardWithQrDestination('https://example.com/qr');

        $configured = Card::query()->whereQrConfigured()->count();
        $unconfigured = Card::query()->whereQrConfigured(false)->count();

        $this->assertSame(1, $configured);
        $this->assertSame(2, $unconfigured, 'both the NULL and the blank destination are unconfigured');
        $this->assertSame(Card::count(), $configured + $unconfigured, 'no card may fall out of both branches');
    }

    public function test_stats_count_every_mode_as_configured(): void
    {
        // One configured card per mode, QR set on all of them.
        foreach (['links', 'contact', 'phone', 'email'] as $mode) {
            $this->cardInMode($mode, true);
        }

        // Two unconfigured: one in contact mode, one left at the links default.
        $this->cardInMode('contact', false);
        $this->card();

        $stats = app(DashboardController::class)->stats();

        $this->assertSame(6, $stats['total']);
        $this->assertSame(4, $stats['qrConfigured']);
        $this->assertSame(4, $stats['nfcConfigured'], 'all four modes count as configured');
        $this->assertSame(4, $stats['fullyConfigured']);
        $this->assertSame(2, $stats['fullyUnconfigured']);
    }

    public function test_a_card_with_qr_only_is_not_fully_configured_or_fully_unconfigured(): void
    {
        $card = $this->card();
        $card->forceFill([
            'qr_destination' => 'https://example.com/qr', 'qr_configured_at' => now(),
        ])->save();

        $stats = app(DashboardController::class)->stats();

        $this->assertSame(1, $stats['qrConfigured']);
        $this->assertSame(0, $stats['nfcConfigured']);
        $this->assertSame(0, $stats['fullyConfigured']);
        $this->assertSame(0, $stats['fullyUnconfigured']);
    }

    /**
     * Uses the query log rather than DB::listen: this helper runs twice in one
     * test, and a listener registered on the first call would still be attached
     * on the second, double-counting every query.
     */
    private function dashboardQueryCount(User $admin): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /**
     * Every row calls nfcModeIsConfigured(), which reads links and the contact
     * profile. Both are meant to be free - the link count arrives as a
     * correlated subquery and the profile is eager-loaded - but nothing else
     * proves it, and an N+1 on the admin's landing page stays invisible until
     * the table is large.
     */
    public function test_dashboard_query_count_does_not_grow_with_the_number_of_cards(): void
    {
        $admin = $this->admin();

        foreach (['links', 'contact', 'phone', 'email'] as $mode) {
            $this->cardInMode($mode, true);
        }
        $small = $this->dashboardQueryCount($admin);

        // 20 rows: still one page, so pagination is not the variable here.
        for ($i = 0; $i < 4; $i++) {
            foreach (['links', 'contact', 'phone', 'email'] as $mode) {
                $this->cardInMode($mode, true);
            }
        }
        $large = $this->dashboardQueryCount($admin);

        $this->assertSame(
            $small,
            $large,
            'the dashboard issued more queries for more rows: an N+1 has been introduced'
        );
    }
}

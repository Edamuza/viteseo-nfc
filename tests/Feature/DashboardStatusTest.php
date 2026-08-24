<?php

namespace Tests\Feature;

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
}

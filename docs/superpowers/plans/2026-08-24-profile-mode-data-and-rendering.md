# Profile Mode: Data and Rendering — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the fifth NFC mode, `Profile`, with its own table, model, platform detection, extracted image pipeline, and a public page rendered at the approved scale — reachable by setting `nfc_mode` directly, with no admin UI yet.

**Architecture:** `nfc_profiles` is a new 1-to-1 table alongside `nfc_contact_profiles`, for the same reason: these columns are null for most cards and do not belong on the hot `cards` row. The accounts list deliberately reuses `nfc_links`, so switching between Links and Profile mode is lossless and needs no data migration — the same links simply render richly instead of as plain buttons. Platform detection is a pure static service with no database or network, so it is unit-testable in isolation. The public view carries its own palette because it is the first public page that is not light-first.

**Tech Stack:** Laravel 12, PHP 8.2+, PHPUnit, GD (already a dependency of the existing contact-photo path), in-memory SQLite for tests. No new Composer packages.

## Global Constraints

- **No new Composer dependencies.** GD and `bacon/bacon-qr-code` are already present; nothing else may be added.
- **PHPUnit, not Pest.** Test classes extend `Tests\TestCase` and use `use RefreshDatabase;`. Data providers use `#[\PHPUnit\Framework\Attributes\DataProvider('name')]`, matching `tests/Feature/DashboardStatusTest.php`.
- Test database is in-memory SQLite (`phpunit.xml`). If a run fails with a MySQL access-denied error, run `php artisan config:clear` — a stale config cache is the cause.
- Columns outside a model's `$fillable` are written in tests with `forceFill([...])->save()`.
- **`avatar_path` and `brand_color` are never mass-assignable.** Both are omitted from `$fillable` and written only by a service from a server-generated value. `card_id` is likewise omitted, matching `NfcContactProfile`.
- **Public pages expose no internal identifiers.** `routes/web.php` states public routes expose "no card inventory, no admin surface and no internal IDs". The profile page must never print `card_number` or the numeric `id`.
- **Public pages are script-free in this increment.** The one clipboard script arrives with the Share modal in increment 3. Nothing in this increment may add `<script>`.
- **All admin- and client-authored copy is plain text**, escaped on output. No HTML, no Markdown.
- **Host matching in `LinkPlatform` must be suffix-anchored** (`$host === $needle || str_ends_with($host, '.'.$needle)`), never `str_contains`. A naive contains check renders the LinkedIn mark for `linkedin.com.attacker.tld`, letting a hostile link borrow a trusted brand. This is a spoofing vector, not a cosmetic detail.
- **`NfcMode` gains exactly one case in this increment: `Profile`.** After this work the enum has five cases and no more.

### What is NOT in this increment

Each of these has a later increment and must not be built here. If a task tempts you toward one, stop and leave it out.

- Share control, Share modal, the profile-URL QR, the avatar QR badge, the public QR download, `profile.vcf`, the `...` overflow menu, Copy link and its toast — **increment 3**.
- The three footer links and their modals (`site_pages`) — **increment 4**. This increment's footer carries only the "Powered by ViteSEO" byline.
- Admin profile UI, avatar upload, the welcome panel fields, and `DominantColor` extraction — **increment 5**. `brand_color` therefore stays null throughout this increment and every page falls back to the default colour.
- Client PIN-gated self-service editing and the `nfc_profile_edits` audit table — **increment 6**.

### Increment-1 guards this work must satisfy

Increment 1 left three tripwires that fire the moment `NfcMode::Profile` exists. They are not obstacles; they are the reason the wiring cannot be forgotten.

1. `DashboardStatusTest::test_the_mode_fixtures_cover_every_mode_in_the_enum` fails unless `modeStates()` covers the new mode.
2. `NfcController::show()` and `Card::nfcModeIsConfigured()` both `match ()` on the enum. PHP throws `UnhandledMatchError` for an unhandled case, so a card in Profile mode would 500 until both learn the mode.
3. `DashboardStatusTest::test_dashboard_query_count_does_not_grow_with_the_number_of_cards` fails if the row status lazy-loads the new relation instead of eager-loading it.

---

### Task 1: The `nfc_profiles` table and the `NfcProfile` model

**Files:**
- Create: `database/migrations/2026_08_24_000001_create_nfc_profiles.php`
- Create: `app/Models/NfcProfile.php`
- Modify: `app/Models/Card.php` — add a `profile()` relation next to `contactProfile()` at line 56
- Test: `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Consumes: nothing from this plan. `Card` and `NfcContactProfile` already exist.
- Produces: `App\Models\NfcProfile` with `$fillable`, `card(): BelongsTo`, `isMeaningful(): bool`, and the constant `NfcProfile::DEFAULT_BRAND_COLOR = '#0f1713'`. `Card::profile(): HasOne`. Tasks 2, 6, 7, 8 and 9 all depend on these names.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NfcProfileTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\NfcProfile;
use App\Services\CardGenerationService;
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
        $card->profile()->save($profile);

        $stored = $card->fresh()->profile;

        $this->assertSame($card->id, $stored->card_id, 'card_id must not be re-pointable by request data');
        $this->assertNull($stored->avatar_path, 'avatar_path is set only from a server-generated name');
        $this->assertNull($stored->brand_color, 'brand_color is derived, never submitted');
    }

    public function test_deleting_a_card_deletes_its_profile(): void
    {
        $card = $this->card();
        $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa']));

        $card->delete();

        $this->assertSame(0, NfcProfile::query()->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=NfcProfileTest`
Expected: FAIL — `Class "App\Models\NfcProfile" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_24_000001_create_nfc_profiles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile-mode payload, 1-to-1 with a card.
 *
 * Separate from `cards` for the same reason `nfc_contact_profiles` is: these
 * columns are null for most cards, and the cards row is read on every public
 * request. Separate from `nfc_contact_profiles` because the two modes describe
 * different things — a vCard's structured name and address versus a profile
 * page's headline and tagline — and merging them would leave both half-empty.
 *
 * The accounts list is NOT here: Profile mode reads the same `nfc_links` rows
 * that Links mode does, so switching between the two modes is lossless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->unique()->constrained('cards')->cascadeOnDelete();

            $table->string('display_name', 120)->nullable();
            $table->string('headline', 200)->nullable();
            $table->string('username', 60)->nullable();     // plain text, never linked
            $table->string('location', 100)->nullable();
            $table->string('tagline', 200)->nullable();

            $table->string('avatar_path', 255)->nullable(); // server-generated name only

            // Admin-authored welcome panel. The columns land now so the public
            // view and the reset path have a stable shape; the admin form that
            // fills them arrives in increment 5.
            $table->string('welcome_eyebrow', 60)->nullable();
            $table->string('welcome_heading', 200)->nullable();
            $table->text('welcome_body')->nullable();
            $table->string('welcome_cta_label', 60)->nullable();
            $table->string('welcome_cta_url', 500)->nullable();

            // Derived from the avatar by DominantColor (increment 5), never
            // submitted. Stored rather than computed per request: decoding an
            // image on every public page view would be absurd for a value that
            // changes only when the photo does.
            $table->string('brand_color', 7)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_profiles');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/NfcProfile.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfcProfile extends Model
{
    use HasFactory;

    /** The page background when no colour has been derived from an avatar. */
    public const DEFAULT_BRAND_COLOR = '#0f1713';

    /**
     * Explicit allow-list. card_id, avatar_path and brand_color are deliberately
     * absent: the first would let request data re-point a profile at another
     * card, the second would let it name a file, and the third is derived from
     * the avatar rather than chosen by anyone.
     */
    protected $fillable = [
        'display_name', 'headline', 'username', 'location', 'tagline',
        'welcome_eyebrow', 'welcome_heading', 'welcome_body',
        'welcome_cta_label', 'welcome_cta_url',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * Is there enough here to show a visitor?
     *
     * A profile page with no name has nothing to be a page about — the headline
     * heads nothing and the avatar fallback has no initials to draw. This gates
     * Profile mode the way NfcContactProfile::hasName() gates Contact mode.
     */
    public function isMeaningful(): bool
    {
        return trim((string) $this->display_name) !== '';
    }
}
```

- [ ] **Step 5: Add the relation to `Card`**

In `app/Models/Card.php`, directly after `contactProfile()` (line 56-59):

```php
    public function profile(): HasOne
    {
        return $this->hasOne(NfcProfile::class);
    }
```

`HasOne` is already imported for `contactProfile()`; no new import is needed.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=NfcProfileTest`
Expected: PASS, 9 tests.

Run: `php artisan test`
Expected: PASS. Nothing else references the new table yet.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_24_000001_create_nfc_profiles.php app/Models/NfcProfile.php app/Models/Card.php tests/Feature/NfcProfileTest.php
git commit -m "feat: add the nfc_profiles table and NfcProfile model

One row per card, with the accounts list deliberately left in nfc_links so
switching between Links and Profile mode loses nothing."
```

---

### Task 2: `NfcProfile::scopeMeaningful()`, the SQL twin

The dashboard counts configured cards across the whole table and cannot instantiate a model per row, so `isMeaningful()` needs a SQL equivalent — exactly the pattern `NfcContactProfile::scopeMeaningful()` already follows. Task 6 calls it from `Card::scopeWhereNfcModeConfigured()`.

**Files:**
- Modify: `app/Models/NfcProfile.php`
- Test: `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Consumes: `NfcProfile::isMeaningful()` and `NfcProfile::$fillable` from Task 1.
- Produces: `NfcProfile::scopeMeaningful(Builder $query): Builder`, called as `->meaningful()`. Task 6 uses it inside a `whereHas`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/NfcProfileTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=NfcProfileTest`
Expected: FAIL — `Call to undefined method ...::meaningful()`.

- [ ] **Step 3: Write minimal implementation**

Add to `app/Models/NfcProfile.php` (and add `use Illuminate\Database\Eloquent\Builder;` to the imports):

```php
    /**
     * SQL twin of isMeaningful().
     *
     * test_the_meaningful_scope_agrees_with_is_meaningful is what keeps the two
     * from drifting. `trim(coalesce(...))` mirrors the PHP `trim((string) ...)`:
     * coalesce collapses NULL to '' first, because `NULL != ''` is UNKNOWN in
     * SQL rather than true, which would drop every unset row.
     *
     * One known limit, shared with NfcContactProfile::scopeMeaningful(): SQL
     * trim() removes spaces while PHP trim() also removes tabs and newlines, so
     * a name of "\t" would differ. It is unreachable today because every write
     * path strips control characters first.
     */
    public function scopeMeaningful(Builder $query): Builder
    {
        return $query->whereRaw("trim(coalesce(display_name, '')) != ''");
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=NfcProfileTest`
Expected: PASS, 16 tests.

Then prove the twin actually constrains both halves: temporarily change `isMeaningful()` to `return (string) $this->display_name !== '';` (dropping the trim). The `spaces only` data set of the agreement test must fail, because PHP would now say true while SQL says false. Restore it.

- [ ] **Step 5: Commit**

```bash
git add app/Models/NfcProfile.php tests/Feature/NfcProfileTest.php
git commit -m "feat: add a tested SQL twin of NfcProfile::isMeaningful

The dashboard counts across the table and cannot hydrate a model per row,
so the rule exists twice; the agreement test is what keeps it one rule."
```

---

### Task 3: `LinkPlatform`

**Files:**
- Create: `app/Services/LinkPlatform.php`
- Test: `tests/Unit/LinkPlatformTest.php`

**Interfaces:**
- Consumes: nothing. Pure static functions, no database, no network.
- Produces: `LinkPlatform::forUrl(string $url): string`, `LinkPlatform::label(string $key): string`, `LinkPlatform::displayUrl(string $url): string`. Task 4 switches on the key `forUrl()` returns; Task 9 calls all three from the view.

Platform keys, fixed for the life of this feature: `x`, `linkedin`, `tiktok`, `github`, `facebook`, `instagram`, `youtube`, `web`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LinkPlatformTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\LinkPlatform;
use PHPUnit\Framework\TestCase;

class LinkPlatformTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function hosts(): array
    {
        return [
            'x'                   => ['https://x.com/its_uriel26', 'x'],
            'twitter is still x'  => ['https://twitter.com/its_uriel26', 'x'],
            'linkedin'            => ['https://www.linkedin.com/in/uriel-papa', 'linkedin'],
            'regional linkedin'   => ['https://ph.linkedin.com/in/uriel-papa', 'linkedin'],
            'tiktok'              => ['https://www.tiktok.com/@its_uriel26', 'tiktok'],
            'github'              => ['https://github.com/urielpapa', 'github'],
            'facebook'            => ['https://www.facebook.com/uriel.papa', 'facebook'],
            'fb.com'              => ['https://fb.com/uriel.papa', 'facebook'],
            'instagram'           => ['https://instagram.com/its_uriel26', 'instagram'],
            'youtube'             => ['https://www.youtube.com/@vitese0', 'youtube'],
            'youtu.be'            => ['https://youtu.be/dQw4w9WgXcQ', 'youtube'],
            'anything else'       => ['https://viteseo.ph/services', 'web'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hosts')]
    public function test_recognised_hosts_map_to_their_platform(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::forUrl($url));
    }

    /** @return array<string, array{string}> */
    public static function spoofedHosts(): array
    {
        return [
            'brand as a subdomain of an attacker' => ['https://linkedin.com.attacker.tld/in/uriel'],
            'brand inside a longer host'          => ['https://notlinkedin.com/in/uriel'],
            'brand in the path only'              => ['https://attacker.tld/linkedin.com/in/uriel'],
            'brand in the query only'             => ['https://attacker.tld/?ref=x.com'],
            'brand as userinfo'                   => ['https://x.com@attacker.tld/'],
        ];
    }

    /**
     * A hostile link must not be able to borrow a trusted brand's icon. The
     * icon is the only signal a visitor has about where a row leads, so this is
     * a spoofing test, not a formatting one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('spoofedHosts')]
    public function test_a_spoofed_host_is_not_given_a_brand_icon(string $url): void
    {
        $this->assertSame('web', LinkPlatform::forUrl($url));
    }

    public function test_an_unparseable_url_is_web(): void
    {
        $this->assertSame('web', LinkPlatform::forUrl('not a url at all'));
        $this->assertSame('web', LinkPlatform::forUrl(''));
    }

    /** @return array<string, array{string, string}> */
    public static function labels(): array
    {
        return [
            'x'         => ['x', 'X'],
            'linkedin'  => ['linkedin', 'LinkedIn'],
            'tiktok'    => ['tiktok', 'TikTok'],
            'github'    => ['github', 'GitHub'],
            'facebook'  => ['facebook', 'Facebook'],
            'instagram' => ['instagram', 'Instagram'],
            'youtube'   => ['youtube', 'YouTube'],
            'web'       => ['web', 'Website'],
            'unknown'   => ['nonsense', 'Website'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labels')]
    public function test_each_key_has_a_label(string $key, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::label($key));
    }

    /** @return array<string, array{string, string}> */
    public static function displayUrls(): array
    {
        return [
            'strips scheme and www'    => ['https://www.x.com/its_uriel26', 'x.com/its_uriel26'],
            'strips a trailing slash'  => ['https://github.com/urielpapa/', 'github.com/urielpapa'],
            'keeps a deep path'        => ['https://linkedin.com/in/uriel-papa-vitese01', 'linkedin.com/in/uriel-papa-vitese01'],
            'keeps a query'            => ['https://viteseo.ph/s?ref=card', 'viteseo.ph/s?ref=card'],
            'bare host'                => ['https://viteseo.ph', 'viteseo.ph'],
            'http too'                 => ['http://viteseo.ph/x', 'viteseo.ph/x'],
            'leaves junk alone'        => ['not a url at all', 'not a url at all'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('displayUrls')]
    public function test_display_url_is_readable_on_a_phone(string $url, string $expected): void
    {
        $this->assertSame($expected, LinkPlatform::displayUrl($url));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=LinkPlatformTest`
Expected: FAIL — `Class "App\Services\LinkPlatform" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/LinkPlatform.php`:

```php
<?php

namespace App\Services;

/**
 * Maps a link URL to a platform key, its label, and a short display form.
 *
 * Pure and static on purpose: no database, no network, no state. The profile
 * page calls it once per link on every render, and a service that can fail or
 * block would put a third-party dependency in the path of a page whose whole
 * job is to load instantly on a phone.
 */
class LinkPlatform
{
    /** platform key => the hosts that mean it */
    private const HOSTS = [
        'x'         => ['x.com', 'twitter.com'],
        'linkedin'  => ['linkedin.com'],
        'tiktok'    => ['tiktok.com'],
        'github'    => ['github.com'],
        'facebook'  => ['facebook.com', 'fb.com'],
        'instagram' => ['instagram.com'],
        'youtube'   => ['youtube.com', 'youtu.be'],
    ];

    private const LABELS = [
        'x'         => 'X',
        'linkedin'  => 'LinkedIn',
        'tiktok'    => 'TikTok',
        'github'    => 'GitHub',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'youtube'   => 'YouTube',
        'web'       => 'Website',
    ];

    public static function forUrl(string $url): string
    {
        $host = self::host($url);

        if ($host === '') {
            return 'web';
        }

        foreach (self::HOSTS as $key => $needles) {
            foreach ($needles as $needle) {
                // Suffix-anchored, never str_contains. A contains check would
                // hand the LinkedIn mark to linkedin.com.attacker.tld, letting
                // a hostile link wear a trusted brand.
                if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                    return $key;
                }
            }
        }

        return 'web';
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? self::LABELS['web'];
    }

    /**
     * "x.com/its_uriel26" rather than the full URL: the panel has to stay
     * readable in one line on a phone.
     */
    public static function displayUrl(string $url): string
    {
        $trimmed = trim($url);
        $short   = preg_replace('#^https?://#i', '', $trimmed) ?? $trimmed;
        $short   = preg_replace('#^www\.#i', '', $short) ?? $short;

        return rtrim($short, '/');
    }

    /**
     * The host, lowercased, with a leading www. removed. parse_url() is what
     * makes the userinfo and path cases safe: "https://x.com@attacker.tld/"
     * has a host of attacker.tld, and a brand name inside a path or query is
     * never part of the host at all.
     */
    private static function host(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return '';
        }

        return preg_replace('#^www\.#i', '', strtolower($host)) ?? '';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=LinkPlatformTest`
Expected: PASS, 34 tests.

Then prove the anti-spoofing test bites: temporarily replace the suffix check with `str_contains($host, $needle)`. The `brand as a subdomain of an attacker` and `brand inside a longer host` data sets must fail. Restore it.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LinkPlatform.php tests/Unit/LinkPlatformTest.php
git commit -m "feat: add LinkPlatform for icon and label lookup

Host matching is suffix-anchored so linkedin.com.attacker.tld cannot
borrow the LinkedIn mark; the icon is the only signal a visitor has."
```

---

### Task 4: The `platform-icon` Blade component

**Files:**
- Create: `resources/views/components/platform-icon.blade.php`
- Test: `tests/Feature/PlatformIconTest.php`

**Interfaces:**
- Consumes: the platform keys `LinkPlatform::forUrl()` returns (Task 3).
- Produces: a Blade component usable as `<x-platform-icon :platform="$key" />`. Task 9 renders it in the icon row and the accounts panel.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlatformIconTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PlatformIconTest extends TestCase
{
    private function render(string $platform): string
    {
        return Blade::render('<x-platform-icon :platform="$platform" />', ['platform' => $platform]);
    }

    /** @return array<string, array{string}> */
    public static function platforms(): array
    {
        return [
            'x'         => ['x'],
            'linkedin'  => ['linkedin'],
            'tiktok'    => ['tiktok'],
            'github'    => ['github'],
            'facebook'  => ['facebook'],
            'instagram' => ['instagram'],
            'youtube'   => ['youtube'],
            'web'       => ['web'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('platforms')]
    public function test_every_platform_renders_one_svg(string $platform): void
    {
        $html = $this->render($platform);

        $this->assertSame(1, substr_count($html, '<svg'), 'exactly one mark per platform');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
    }

    public function test_each_platform_renders_a_distinct_mark(): void
    {
        $marks = [];

        foreach (array_map(fn (array $row) => $row[0], self::platforms()) as $platform) {
            $marks[$platform] = $this->render($platform);
        }

        $this->assertCount(
            count($marks),
            array_unique($marks),
            'two platforms render the same mark: a visitor cannot tell those rows apart'
        );
    }

    public function test_an_unknown_platform_falls_back_to_the_web_mark(): void
    {
        $this->assertSame($this->render('web'), $this->render('nonsense'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PlatformIconTest`
Expected: FAIL — unresolvable component `platform-icon`.

- [ ] **Step 3: Write the component**

Create the directory `resources/views/components/` and the file `platform-icon.blade.php`.

**Copy the five brand marks verbatim** from the approved mockup rather than retyping path data — mistyped path data renders as a smear and no test would catch it. The mockup is `docs/superpowers/specs/mockups/2026-08-24-nfc-profile.html`:

| Platform | Mockup line |
|---|---|
| `x` | 285 |
| `linkedin` | 286 |
| `tiktok` | 287 |
| `github` | 288 |
| `facebook` | 289 |

Take only the `<svg viewBox="0 0 24 24">…</svg>` element from each line, dropping the surrounding `<a>`.

The mockup has no Instagram, YouTube or globe mark. Use these, built from primitives so they are readable and verifiable:

```blade
@props(['platform' => 'web'])

{{-- One inline SVG per platform key from LinkPlatform::forUrl(). Inline rather
     than sprited: the page is a single request with no asset pipeline, and
     these marks are small. --}}
@switch($platform)
  @case('x')
    {{-- paste the mockup's line 285 svg here --}}
    @break
  @case('linkedin')
    {{-- paste the mockup's line 286 svg here --}}
    @break
  @case('tiktok')
    {{-- paste the mockup's line 287 svg here --}}
    @break
  @case('github')
    {{-- paste the mockup's line 288 svg here --}}
    @break
  @case('facebook')
    {{-- paste the mockup's line 289 svg here --}}
    @break
  @case('instagram')
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.6" y="2.6" width="18.8" height="18.8" rx="5.4"/><circle cx="12" cy="12" r="4.4"/><circle cx="17.4" cy="6.6" r="1.2" fill="currentColor" stroke="none"/></svg>
    @break
  @case('youtube')
    <svg viewBox="0 0 24 24"><path d="M21.6 7.2a2.5 2.5 0 0 0-1.76-1.77C18.25 5 12 5 12 5s-6.25 0-7.84.43A2.5 2.5 0 0 0 2.4 7.2C2 8.8 2 12 2 12s0 3.2.4 4.8a2.5 2.5 0 0 0 1.76 1.77C5.75 19 12 19 12 19s6.25 0 7.84-.43a2.5 2.5 0 0 0 1.76-1.77C22 15.2 22 12 22 12s0-3.2-.4-4.8zM10 15.5v-7l6 3.5-6 3.5z"/></svg>
    @break
  @default
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9.2"/><path d="M2.8 12h18.4"/><path d="M12 2.8c2.6 2.6 4 5.8 4 9.2s-1.4 6.6-4 9.2c-2.6-2.6-4-5.8-4-9.2s1.4-6.6 4-9.2z"/></svg>
@endswitch
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PlatformIconTest`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/platform-icon.blade.php tests/Feature/PlatformIconTest.php
git commit -m "feat: add the platform-icon component

Eight inline marks, one per LinkPlatform key, with a globe fallback."
```

---

### Task 5: Extract `ImageIngest` from the contact-photo path

`CardController::storeContactPhoto()` (`app/Http/Controllers/Admin/CardController.php:351-391`) holds the hardened upload path: decode with GD, cap dimensions, re-encode to strip EXIF and any smuggled payload, store under a random server-generated name. Increment 5 adds an avatar upload. Copy-pasting security-critical code into a second place is how one of the two eventually drifts, so it moves to a service now, while there is still exactly one caller to verify against.

One behavioural improvement comes with the move: the current code calls `abort_if(..., 422)`, which shows an admin a raw error page. The service throws instead, and the controller turns it into an ordinary validation error on the file field.

**Files:**
- Create: `app/Services/ImageIngest.php`
- Create: `app/Exceptions/UnreadableImageException.php`
- Modify: `app/Http/Controllers/Admin/CardController.php` — inject the service, replace the `storeContactPhoto()` call site, delete the private method
- Test: `tests/Feature/ImageIngestTest.php`

**Interfaces:**
- Consumes: nothing from this plan.
- Produces: `ImageIngest::store(UploadedFile $file, string $directory, int $maxEdge): string` returning the stored relative path, throwing `App\Exceptions\UnreadableImageException` when the upload is not a decodable image. Increment 5 calls it with `('profile-avatars', 512)`.

**Known follow-up, deliberately not built here.** Spec section 5 says the avatar path hands the decoded GD resource to `DominantColor` *before it is freed*, so the file is never read twice. `store()` as written below frees the image internally, so increment 5 will need a seam — most likely a second method that returns both the path and the extracted colour, sharing one private decode-and-store helper. Building that seam now would mean designing an interface for a caller that does not exist and cannot be tested, so it waits. This note exists so increment 5 does not mistake the closed resource for an oversight.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ImageIngestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\UnreadableImageException;
use App\Services\ImageIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageIngestTest extends TestCase
{
    use RefreshDatabase;

    /** A real PNG, so GD has something genuine to decode. */
    private function png(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 70));

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'photo.png', 'image/png', null, true);
    }

    public function test_the_stored_file_is_a_jpeg_whatever_was_uploaded(): void
    {
        Storage::fake('public');

        $path = app(ImageIngest::class)->store($this->png(400, 300), 'contact-photos', 800);

        $this->assertStringStartsWith('contact-photos/', $path);
        $this->assertStringEndsWith('.jpg', $path);

        $stored = Storage::disk('public')->get($path);
        $this->assertSame('image/jpeg', (string) (getimagesizefromstring($stored)['mime'] ?? ''));
    }

    public function test_the_filename_carries_nothing_from_the_upload(): void
    {
        Storage::fake('public');

        $file = $this->png(40, 40);
        $path = app(ImageIngest::class)->store($file, 'contact-photos', 800);

        $this->assertStringNotContainsString('photo', $path, 'the uploaded name must not reach the filesystem');
        $this->assertMatchesRegularExpression('#^contact-photos/[0-9a-f]{32}\.jpg$#', $path);
    }

    public function test_an_oversized_image_is_scaled_to_the_requested_longest_edge(): void
    {
        Storage::fake('public');

        $path = app(ImageIngest::class)->store($this->png(1200, 600), 'profile-avatars', 512);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame(512, $width, 'the long edge is capped');
        $this->assertSame(256, $height, 'the aspect ratio is preserved');
    }

    public function test_an_image_within_the_cap_is_left_at_its_own_size(): void
    {
        Storage::fake('public');

        $path = app(ImageIngest::class)->store($this->png(200, 100), 'profile-avatars', 512);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame([200, 100], [$width, $height]);
    }

    public function test_a_non_image_is_rejected_with_a_domain_exception(): void
    {
        Storage::fake('public');

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, "<?php echo 'not an image';");
        $file = new UploadedFile($path, 'payload.png', 'image/png', null, true);

        $this->expectException(UnreadableImageException::class);

        app(ImageIngest::class)->store($file, 'contact-photos', 800);
    }

    public function test_a_rejected_upload_stores_nothing(): void
    {
        Storage::fake('public');

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, 'still not an image');
        $file = new UploadedFile($path, 'payload.png', 'image/png', null, true);

        try {
            app(ImageIngest::class)->store($file, 'contact-photos', 800);
        } catch (UnreadableImageException) {
            // expected
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ImageIngestTest`
Expected: FAIL — `Class "App\Services\ImageIngest" not found`.

- [ ] **Step 3: Write the exception and the service**

Create `app/Exceptions/UnreadableImageException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The upload could not be decoded as an image.
 *
 * A domain exception rather than abort(422): the caller is a form handler, and
 * a bad file should read as a validation error on the file field like every
 * other form mistake, not as a raw error page.
 */
class UnreadableImageException extends RuntimeException {}
```

Create `app/Services/ImageIngest.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\UnreadableImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Storing an uploaded image safely.
 *
 * Extracted from CardController so the contact photo and the profile avatar
 * cannot drift apart. Every guarantee below is load-bearing:
 *
 *  - the file is re-encoded through GD, which discards EXIF, appended archives
 *    and any PHP smuggled inside an otherwise-valid image
 *  - the extension comes from that re-encode, never from the upload name
 *  - the filename is server-generated random hex, so nothing user-supplied
 *    reaches the filesystem
 *  - dimensions are capped, so a huge upload cannot be served back verbatim
 */
class ImageIngest
{
    public function store(UploadedFile $file, string $directory, int $maxEdge): string
    {
        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false) {
            throw new UnreadableImageException('Could not read the uploaded image.');
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw new UnreadableImageException('That file is not a readable image.');
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxEdge || $height > $maxEdge) {
            $scale   = $maxEdge / max($width, $height);
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));

            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();
        imagejpeg($image, null, 85);   // re-encode: output is a clean JPEG, nothing else
        $clean = (string) ob_get_clean();
        imagedestroy($image);

        $path = trim($directory, '/').'/'.bin2hex(random_bytes(16)).'.jpg';
        Storage::disk('public')->put($path, $clean);

        return $path;
    }
}
```

- [ ] **Step 4: Switch the contact-photo call site over**

In `app/Http/Controllers/Admin/CardController.php`:

Add to the imports:

```php
use App\Exceptions\UnreadableImageException;
use App\Services\ImageIngest;
```

Add to the constructor promotion list, after `ContactPhotoStorage $photos`:

```php
        private readonly ImageIngest $images,
```

Replace the photo branch inside `updateContactProfile()` (currently `if ($file = $request->file('photo')) { … $this->storeContactPhoto($file) … }`):

```php
        if ($file = $request->file('photo')) {
            $old = $profile->photo_path;

            try {
                $profile->photo_path = $this->images->store($file, 'contact-photos', 800);
            } catch (UnreadableImageException $e) {
                // A bad file reads as a normal validation error on the field it
                // came from, rather than as a raw 422 page.
                return back()->withErrors(['photo' => $e->getMessage()])->withInput($request->except('photo'));
            }

            $this->photos->forget($old, $card);
        }
```

Delete the now-unused private `storeContactPhoto()` method (lines 351-391) and its docblock. Leave the `use Illuminate\Support\Facades\Storage;` import only if something else in the file still uses `Storage::` — after this change nothing does, so remove that import too and confirm with a grep.

- [ ] **Step 5: Add the controller-level test**

Add to `tests/Feature/ImageIngestTest.php`:

```php
    public function test_a_bad_contact_photo_upload_is_a_validation_error_not_an_error_page(): void
    {
        Storage::fake('public');

        $card  = app(\App\Services\CardGenerationService::class)->generate(1)->first();
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'a@example.com'],
            ['name' => 'A', 'password' => \Illuminate\Support\Facades\Hash::make('correct-horse-1')]
        );

        $path = tempnam(sys_get_temp_dir(), 'ingest').'.png';
        file_put_contents($path, 'not an image');

        $this->actingAs($admin)
            ->patch('/admin/cards/'.$card->public_id.'/contact', [
                'first_name' => 'John',
                'photo'      => new UploadedFile($path, 'payload.png', 'image/png', null, true),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('photo');
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ImageIngestTest`
Expected: PASS, 7 tests.

Run: `php artisan test`
Expected: PASS. `AdminTest` already covers a successful contact-photo upload; that path now runs through the service, so a failure there means the extraction changed behaviour and the service is wrong, not the test.

- [ ] **Step 7: Commit**

```bash
git add app/Services/ImageIngest.php app/Exceptions/UnreadableImageException.php app/Http/Controllers/Admin/CardController.php tests/Feature/ImageIngestTest.php
git commit -m "refactor: extract ImageIngest from the contact-photo path

The avatar upload in a later increment needs the same hardening, and
copy-pasting it is how one of the two copies eventually drifts. A bad
upload now reads as a validation error instead of a raw 422 page."
```

---

### Task 6: The `Profile` mode case, wired end to end

This is the task that adds the enum case, and therefore the task that must satisfy all three increment-1 guards at once. Splitting it further would leave the suite red between commits.

The public render arm deliberately returns the existing not-ready page for now; Task 8 replaces it with the real view. That keeps this task's deliverable honest and testable: a card can be put into Profile mode, the dashboard reports it correctly, and the public URL degrades gracefully instead of throwing `UnhandledMatchError`.

**Files:**
- Modify: `app/Enums/NfcMode.php`
- Modify: `app/Models/Card.php` — the `match` in `nfcModeIsConfigured()` (line 74-82) and the closure in `scopeWhereNfcModeConfigured()` (line 96-114)
- Modify: `app/Http/Controllers/Public/NfcController.php` — the `match` in `show()` (line 39-47)
- Modify: `app/Http/Controllers/Admin/DashboardController.php:22-28` — the eager load
- Test: `tests/Feature/DashboardStatusTest.php`, `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Consumes: `NfcProfile::isMeaningful()` (Task 1), `NfcProfile::scopeMeaningful()` (Task 2), `Card::profile()` (Task 1).
- Produces: `NfcMode::Profile`; a `showProfile(Card $card): Response` private method on `NfcController` that Task 8 rewrites; the `'profile'` arm of the `cardInMode()` test helper and the two `modeStates()` entries that Tasks 7, 8 and 9 reuse.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/DashboardStatusTest.php`, add the `'profile'` arm to the `match` inside `cardInMode()` (currently at lines 127-134), after the `'email'` arm:

```php
            'profile' => $card->profile()->save($card->profile()->make(['display_name' => 'Uriel Papa'])),
```

Add two entries to `modeStates()` (lines 146-158), after the email pair:

```php
            'profile configured'    => ['profile', true],
            'profile unconfigured'  => ['profile', false],
```

Add to `tests/Feature/NfcProfileTest.php`:

```php
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

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=NfcProfileTest`
Expected: FAIL — the fixtures write `nfc_mode = 'profile'`, which `Card::nfcMode()` cannot cast, so the card falls back to `NfcMode::Links` and the links setup form renders. `assertDontSee('activation_pin')` fails.

Run: `php artisan test --filter=DashboardStatusTest`
Expected: FAIL — `UnhandledMatchError` from `cardInMode('profile', …)`.

- [ ] **Step 3: Add the enum case**

In `app/Enums/NfcMode.php`:

```php
    case Profile = 'profile';
```

after `case Email`, plus the two arms:

```php
            self::Profile => 'Profile Page',
```

in `label()`, and:

```php
            self::Profile => 'Shows a rich profile page with avatar, bio and a list of linked accounts.',
```

in `description()`.

`NfcMode::all()` already returns `self::cases()`, so the admin mode selector picks the new case up with no change.

- [ ] **Step 4: Teach both status predicates about the mode**

In `app/Models/Card.php`, add to the `match` in `nfcModeIsConfigured()`:

```php
            NfcMode::Profile => (bool) $this->profile?->isMeaningful(),
```

and add to the closure in `scopeWhereNfcModeConfigured()`, after the Email arm:

```php
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Profile->value)
                    ->whereHas('profile', fn ($p) => $p->meaningful()));
```

Move the `;` so it terminates the new last arm.

- [ ] **Step 5: Add the public dispatch arm**

In `app/Http/Controllers/Public/NfcController.php`, add to the `match` in `show()`:

```php
            NfcMode::Profile => $this->showProfile($card),
```

and add the method next to `showEmail()`:

```php
    /**
     * Profile mode.
     *
     * Every card renders the not-ready page for now; the profile view lands in
     * the next task and adds the branch. Existing at all is what matters here:
     * an enum case with no match arm throws UnhandledMatchError, which would
     * take down a live public URL.
     */
    private function showProfile(Card $card): Response
    {
        return $this->notReady($card);
    }
```

Do not write the `isMeaningful()` branch yet. A branch whose two sides return the same value is dead code, and a reviewer would be right to reject it. The two not-ready tests above are black-box tests of observable behaviour: they pass now, and they keep passing in Task 8 once the branch is real.

- [ ] **Step 6: Eager-load the relation the row status now reads**

In `app/Http/Controllers/Admin/DashboardController.php`, extend the `->with([...])` call:

```php
            ->with([
                // Contact-mode status needs the name columns and nothing else.
                // Selecting a subset keeps a 25-row page at a constant query
                // count without hydrating whole profiles, including notes and
                // addresses.
                'contactProfile:id,card_id,first_name,middle_name,last_name,suffix,company',
                // Profile-mode status reads display_name alone.
                'profile:id,card_id,display_name',
            ]);
```

- [ ] **Step 7: Make the N+1 guard cover the new mode**

In `tests/Feature/DashboardStatusTest.php`, replace both hardcoded `['links', 'contact', 'phone', 'email']` loops inside `test_dashboard_query_count_does_not_grow_with_the_number_of_cards` with the enum, so a sixth mode can never slip past this guard either:

```php
        foreach (NfcMode::cases() as $mode) {
            $this->cardInMode($mode->value, true);
        }
```

and, in the second loop:

```php
        for ($i = 0; $i < 4; $i++) {
            foreach (NfcMode::cases() as $mode) {
                $this->cardInMode($mode->value, true);
            }
        }
```

`NfcMode` is already imported in that file.

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter=NfcProfileTest`
Expected: PASS, 20 tests.

Run: `php artisan test --filter=DashboardStatusTest`
Expected: PASS. The count rises by two data sets on each `modeStates`-driven test.

Run: `php artisan test`
Expected: PASS, everything.

Then prove the eager load is what keeps the page flat: temporarily remove `'profile:id,card_id,display_name'` from the `with()` array and confirm `test_dashboard_query_count_does_not_grow_with_the_number_of_cards` fails with a larger count for more rows. Restore it.

- [ ] **Step 9: Commit**

```bash
git add app/Enums/NfcMode.php app/Models/Card.php app/Http/Controllers/Public/NfcController.php app/Http/Controllers/Admin/DashboardController.php tests/Feature/DashboardStatusTest.php tests/Feature/NfcProfileTest.php
git commit -m "feat: add NfcMode::Profile, wired into status and dispatch

Both status predicates, the public dispatch and the dashboard eager load
learn the mode in one commit: a mode known to the enum but not to the
match arms would 500 a live public URL."
```

---

### Task 7: Reset and the dashboard learn about profile data

Increment 1 centralised reset into `CardResetService` so that "what counts as the NFC payload" is one list in one file, and wrote down why: *a mode missed here means a card the admin was told is blank still answers with someone else's data*. Profile is that mode until this task lands. The avatar is the sharper edge — a file left on the public disk outlives the row that pointed at it.

**Files:**
- Modify: `app/Services/CardResetService.php`
- Rename: `app/Services/ContactPhotoStorage.php` → `app/Services/PublicImageStorage.php` (and its callers)
- Rename: `tests/Feature/ContactPhotoStorageTest.php` → `tests/Feature/PublicImageStorageTest.php`
- Modify: `app/Http/Controllers/Admin/CardController.php` — the three `$this->photos->forget(...)` call sites and the constructor type
- Test: `tests/Feature/CardResetTest.php`

**Interfaces:**
- Consumes: `Card::profile()` and `NfcProfile` (Task 1); `NfcMode::Profile` (Task 6); the existing `CardResetService::resetNfcSide()` / `resetEverything()` and the private `reset()` helper.
- Produces: `PublicImageStorage::forget(?string $path, Card $card): void` — the same behaviour as `ContactPhotoStorage::forget()` under a name that covers avatars too. Increment 5 calls it when replacing or deleting an avatar.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/CardResetTest.php`, extend the shared fixture `fullyPopulatedCard()` so a reset has profile data to fail on. Add before the closing `return $card->fresh();`:

```php
        Storage::disk('public')->put('profile-avatars/old.jpg', 'not-really-a-jpeg');

        $profile = $card->profile()->make(['display_name' => 'John Doe', 'headline' => 'CEO, Acme Ltd']);
        $profile->avatar_path = 'profile-avatars/old.jpg';
        $card->profile()->save($profile);
```

Add to `assertNfcSideIsClear()`, next to the contact-profile assertion:

```php
        $this->assertNull($card->profile, 'profile');
```

Add these tests:

```php
    public function test_resetting_the_nfc_side_deletes_the_profile_avatar_file(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('profile-avatars/old.jpg');

        app(CardResetService::class)->resetNfcSide($card);

        Storage::disk('public')->assertMissing('profile-avatars/old.jpg');
    }

    public function test_resetting_the_nfc_side_clears_the_profile_row(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->assertNotNull($card->profile, 'the fixture has a profile to clear');

        app(CardResetService::class)->resetNfcSide($card);

        $this->assertNull($card->fresh()->profile);
    }
```

The public-page counterpart — proving a reset card no longer *serves* the previous holder's name — belongs in Task 8, where a page exists to serve it. Do not write it here against the not-ready page: an assertion that the name is absent from a page that never renders names cannot fail, and a test that cannot fail is worse than no test.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CardResetTest`
Expected: FAIL — `assertNfcSideIsClear` reports a surviving `profile`, and the avatar file is still on the fake disk.

- [ ] **Step 3: Rename the storage service**

`ContactPhotoStorage` now handles two kinds of image, and a name that says "contact photo" while deleting avatars is the kind of drift that makes the next reader miss a call site.

1. `git mv app/Services/ContactPhotoStorage.php app/Services/PublicImageStorage.php`
2. `git mv tests/Feature/ContactPhotoStorageTest.php tests/Feature/PublicImageStorageTest.php`
3. Rename the class in both files, and the test class to `PublicImageStorageTest`.
4. Update the docblock's first line to "Removing a publicly served image from the public disk." and keep the rest — the reasoning about a file outliving its row applies identically to an avatar.
5. Update the type in both constructors: `CardController` and `CardResetService`.
6. Grep to confirm no `ContactPhotoStorage` reference survives: `rg -n ContactPhotoStorage --glob '!vendor'` must return nothing.

- [ ] **Step 4: Teach the reset about profile data**

In `app/Services/CardResetService.php`, inside the private `reset()` helper, capture the second path before the transaction:

```php
        $photoPath  = $card->contactProfile?->photo_path;
        $avatarPath = $card->profile?->avatar_path;
```

Inside the transaction, next to the contact-profile delete:

```php
            if ($profile = $card->profile) {
                $profile->delete();
            }
```

Rename the existing `$profile` variable for the contact row to `$contact` so the two are not confused:

```php
            if ($contact = $card->contactProfile) {
                $contact->delete();
            }
```

After the transaction, alongside the existing photo delete:

```php
        $this->photos->forget($photoPath, $card);
        $this->photos->forget($avatarPath, $card);
```

Leave the promoted property named `$photos` in both `CardResetService` and `CardController`. Only the class name changes. `CardController` already has an `$images` property holding `ImageIngest` from Task 5, and reusing that name for a second, unrelated service in the same constructor is how the wrong one gets called.

Extend the relation unset:

```php
        $card->unsetRelation('contactProfile');
        $card->unsetRelation('profile');
```

Update the class docblock: the sentence "When a mode is added, this file is the single place that has to learn about it" is now demonstrated — add "Profile mode was added in increment 2 and needed exactly these four lines."

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=CardResetTest`
Expected: PASS.

Run: `php artisan test --filter=PublicImageStorageTest`
Expected: PASS, 4 tests.

Run: `php artisan test`
Expected: PASS.

Then prove the new clearing is load-bearing: temporarily remove the `$card->profile` delete from the transaction and confirm `assertNfcSideIsClear` fails on `profile`; temporarily remove the `forget($avatarPath, …)` line and confirm the avatar-file test fails. Restore both.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "fix: clear profile data and the avatar file on reset

A mode missing from the reset service means a card the admin was told is
blank still answers with someone else's data, and an avatar left on the
public disk outlives the row that pointed at it. Renames the storage
helper to PublicImageStorage now that it handles both image kinds."
```

---

### Task 8: The public profile page

**Files:**
- Create: `resources/views/public/nfc-profile.blade.php`
- Modify: `app/Http/Controllers/Public/NfcController.php` — `showProfile()` renders the view
- Modify: `app/Models/NfcProfile.php` — add `initials()`, `brandColor()`, `brandColorSoftRgba()`
- Test: `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Consumes: `NfcProfile::isMeaningful()`, `NfcProfile::DEFAULT_BRAND_COLOR` (Task 1); `showProfile()` (Task 6).
- Produces: `NfcProfile::initials(): string`, `NfcProfile::brandColor(): string`, `NfcProfile::brandColorSoftRgba(): string`. Task 9 renders the accounts panel into this view.

Scale tokens, from the approved revision-2 render (spec section 14) — use exactly these:

| Token | Value |
|---|---|
| column max-width | 680px |
| base font | 17px |
| `h1` | 1.95rem |
| avatar | 110px |
| cover height | 150px |
| card radius | 20px |
| card padding | 28px |
| account row icon | 21px |

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/NfcProfileTest.php`:

```php
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
        $this->assertStringNotContainsString('onerror=', $body);
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
```

And add to `tests/Feature/CardResetTest.php` the public-page counterpart that Task 7 deferred to here, now that a page exists to serve the name:

```php
    public function test_a_reset_card_no_longer_serves_the_previous_holders_profile_page(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $card->forceFill(['nfc_mode' => 'profile'])->save();

        // The "before" half is what makes the "after" half meaningful: the page
        // demonstrably served this name a moment ago.
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('John Doe');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');
        $this->selectNfcMode($card, 'profile');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('John Doe', $body, 'name survived the reset');
        $this->assertStringNotContainsString('CEO, Acme Ltd', $body, 'headline survived the reset');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=NfcProfileTest`
Expected: FAIL — `showProfile()` still returns the not-ready page, so none of the identity content is present.

- [ ] **Step 3: Add the three model helpers**

Add to `app/Models/NfcProfile.php`:

```php
    /** "UP" for the avatar placeholder — first and last word, at most two letters. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->display_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $letters = count($words) === 1
            ? [$words[0]]
            : [$words[0], $words[count($words) - 1]];

        return strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), $letters)));
    }

    /**
     * The page background. Always a valid hex colour, because it is
     * interpolated into a style attribute.
     *
     * DominantColor normalises every derived value into a dark lightness band,
     * which is why --on-brand can be a fixed light value with no luminance
     * branch. A stored value that is not a hex colour is treated as absent
     * rather than trusted.
     */
    public function brandColor(): string
    {
        $stored = (string) $this->brand_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $stored) === 1
            ? strtolower($stored)
            : self::DEFAULT_BRAND_COLOR;
    }

    /** The same colour at low alpha, for the cover band. */
    public function brandColorSoftRgba(): string
    {
        [$r, $g, $b] = sscanf($this->brandColor(), '#%02x%02x%02x');

        return sprintf('rgba(%d,%d,%d,.34)', $r, $g, $b);
    }
```

- [ ] **Step 4: Create the view**

Create `resources/views/public/nfc-profile.blade.php`. The CSS transfers from the approved mockup `docs/superpowers/specs/mockups/2026-08-24-nfc-profile.html` lines 23-231, **minus** the blocks belonging to later increments:

| Mockup CSS block | Lines | This increment? |
|---|---|---|
| `:root`, `*`, `body`, `.wrap` | 23-49 | yes — but `--brand` / `--brand-soft` come from the model, not literals |
| overflow menu (`.topbar`, `details.menu`, `.menu-panel`) | 51-70 | no — increment 3 |
| profile card, cover, avatar | 72-84 | yes |
| `details.qr`, `.qr-pop` | 85-96 | no — increment 3 |
| `h1`, `.headline`, `.meta` | 98-100 | yes |
| `.iconrow` | 102-104 | yes |
| `.tagline` | 106 | yes |
| `.sharebtn` | 108-113 | no — increment 3 |
| accounts card | 115-124 | yes (Task 9 fills it) |
| `details.adv` | 126-133 | yes |
| `.welcome` | 135-146 | no — increment 5 |
| `.adv-body`, `.kv` | 148-153 | yes |
| `footer`, `.by` | 155-159 | yes, byline only — the nav is increment 4 |
| modals, qr stage, linkbar, toast | 161-230 | no — increments 3 and 4 |

The body:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title>{{ $profile->display_name }}</title>
{{-- The brand colour is derived from the avatar and normalised into a dark
     band, so --on-brand can be a fixed light value with no luminance branch.
     brandColor() guarantees a hex value; nothing else may be interpolated
     into this attribute. --}}
<style>
:root{
  --brand:{{ $profile->brandColor() }};
  --brand-soft:{{ $profile->brandColorSoftRgba() }};
  --on-brand:#e8f1ec;
  --on-brand-dim:#a9c2b6;
  --card:#fff;
  --text:#101418;
  --soft:#3f4854;
  --muted:#6b7480;
  --line:#e6e8eb;
  --pill:#e9edf0;
  --radius:20px;
}
*{box-sizing:border-box}
body{
  margin:0;background:var(--brand);color:var(--text);
  font:17px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  padding:26px 20px calc(38px + env(safe-area-inset-bottom));
}
.wrap{max-width:680px;margin:0 auto}

.card{background:var(--card);border-radius:var(--radius);overflow:hidden;
 border:1px solid rgba(0,0,0,.06);margin-bottom:18px}
/* Cover is the brand colour at low alpha - same hue, lighter. */
.cover{height:150px;background:var(--brand-soft)}
.idblock{padding:0 28px 26px}
.avatar-row{margin-top:-54px;margin-bottom:16px}
.avatar-slot{position:relative;width:110px;height:110px}
.avatar-fallback{
  width:110px;height:110px;border-radius:50%;border:4px solid var(--card);
  display:grid;place-items:center;font-weight:640;font-size:2.1rem;color:#fff;
  background:var(--brand);
}
.avatar-img{width:110px;height:110px;border-radius:50%;border:4px solid var(--card);
 object-fit:cover;display:block}

h1{font-size:1.95rem;font-weight:680;margin:0;letter-spacing:-.024em;overflow-wrap:anywhere}
.headline{color:var(--soft);margin:7px 0 0;font-size:1.02rem;overflow-wrap:anywhere}
.meta{color:var(--muted);margin:4px 0 0;font-size:.94rem}

.iconrow{display:flex;align-items:center;gap:17px;margin:18px 0 0;flex-wrap:wrap}
.iconrow a{color:#20262e;display:grid;place-items:center}
.iconrow svg{width:19px;height:19px;fill:currentColor;display:block}

.tagline{margin:20px 0 0;font-size:1.02rem;color:var(--soft);overflow-wrap:anywhere}

.card-pad{padding:24px 28px}
h2{font-size:1.14rem;font-weight:650;margin:0 0 6px;letter-spacing:-.012em}
.acct{display:flex;gap:15px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--line)}
.acct:last-child{border-bottom:0}
.acct-ico{flex:0 0 auto;width:21px;padding-top:2px;color:#20262e}
.acct-ico svg{width:21px;height:21px;fill:currentColor;display:block}
.acct-name{font-size:1rem;font-weight:610}
.acct-url{font-size:.9rem;color:var(--muted);overflow-wrap:anywhere;text-decoration:none;display:block;margin-top:2px}
.acct-url:hover{text-decoration:underline}

details.adv{margin:6px 0 0;text-align:center}
details.adv>summary{
  list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;
  background:var(--pill);color:#2b333c;border-radius:999px;padding:11px 19px;
  font-size:.92rem;font-weight:550;
}
details.adv>summary::-webkit-details-marker{display:none}
.adv-body{background:var(--card);border:1px solid rgba(0,0,0,.06);border-radius:16px;
 margin-top:14px;padding:6px 24px;text-align:left}
.kv{display:flex;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--line);font-size:.94rem}
.kv:last-child{border-bottom:0}
.kv .k{color:var(--muted)}
.kv .v{text-align:right;overflow-wrap:anywhere}

footer{margin-top:32px;text-align:center}
.by{color:var(--on-brand-dim);font-size:.78rem}
</style>
</head>
<body>
<div class="wrap">

  <section class="card">
    <div class="cover"></div>
    <div class="idblock">

      <div class="avatar-row">
        <div class="avatar-slot">
          @if ($profile->avatar_path)
            <img class="avatar-img" src="{{ asset('storage/'.$profile->avatar_path) }}" alt="">
          @else
            <div class="avatar-fallback">{{ $profile->initials() }}</div>
          @endif
        </div>
      </div>

      <h1>{{ $profile->display_name }}</h1>
      @if ($profile->headline)<p class="headline">{{ $profile->headline }}</p>@endif
      @if ($profile->username || $profile->location)
        <p class="meta">{{ trim(implode(' · ', array_filter([$profile->username, $profile->location]))) }}</p>
      @endif

      {{-- icon row: Task 9 --}}

      @if ($profile->tagline)<p class="tagline">{{ $profile->tagline }}</p>@endif
    </div>
  </section>

  {{-- accounts card: Task 9 --}}

  <details class="adv">
    <summary>Advanced details</summary>
    <div class="adv-body">
      {{-- The permanent URL, never the card number: publishing a sequential
           internal id tells a visitor how many cards exist. --}}
      <div class="kv"><span class="k">Permanent URL</span><span class="v">{{ $card->nfcUrl() }}</span></div>
      <div class="kv"><span class="k">Last updated</span><span class="v">{{ $profile->updated_at->format('j F Y') }}</span></div>
    </div>
  </details>

  <footer>
    {{-- The three global page links land in increment 4 with site_pages. --}}
    <div class="by">Powered by ViteSEO</div>
  </footer>

</div>
</body>
</html>
```

The `.avatar-img` rule above is the one addition to the mockup's CSS: the mockup only ever shows the initials fallback, because no avatar can be uploaded until increment 5.

- [ ] **Step 5: Render the view from the controller**

In `app/Http/Controllers/Public/NfcController.php`, replace the placeholder return in `showProfile()`:

```php
    private function showProfile(Card $card): Response
    {
        $profile = $card->profile;

        if (! $profile || ! $profile->isMeaningful()) {
            return $this->notReady($card);
        }

        return $this->noStore(response()->view('public.nfc-profile', [
            'card'    => $card,
            'profile' => $profile,
        ]));
    }
```

Update the method docblock: drop the sentence about the render being temporary.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=NfcProfileTest`
Expected: PASS.

Run: `php artisan test --filter=CardResetTest`
Expected: PASS, including the new public-page test.

Run: `php artisan test`
Expected: PASS.

Then prove the escaping test bites: temporarily change `{{ $profile->display_name }}` to `{!! $profile->display_name !!}` and confirm `test_every_profile_field_is_escaped_on_the_page` fails. Restore it.

- [ ] **Step 7: Commit**

```bash
git add resources/views/public/nfc-profile.blade.php app/Http/Controllers/Public/NfcController.php app/Models/NfcProfile.php tests/Feature/NfcProfileTest.php tests/Feature/CardResetTest.php
git commit -m "feat: render the public profile page

Identity block, brand-coloured background derived from the avatar, and
advanced details at the approved 680px scale. The card number stays off
the page and every field is escaped."
```

---

### Task 9: The accounts panel and the social icon row

The same active links Links mode serves as plain buttons render here as an icon row under the identity block and a labelled Accounts panel below it.

**Files:**
- Modify: `resources/views/public/nfc-profile.blade.php`
- Modify: `app/Http/Controllers/Public/NfcController.php` — pass the links to the view
- Test: `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Consumes: `LinkPlatform::forUrl()`, `::label()`, `::displayUrl()` (Task 3); `<x-platform-icon>` (Task 4); the view from Task 8.
- Produces: nothing later tasks depend on.

Rule for the two lists, since the spec shows both filled with brand links: the **icon row** carries only links whose platform is not `web` — a row of six identical globes is noise, not navigation. The **Accounts panel** lists every active link, globes included, because that is the complete list a visitor came for.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/NfcProfileTest.php`:

```php
    private function link(Card $card, string $url, string $title = 'Link', bool $active = true, int $order = 0): void
    {
        $card->nfcLinks()->create([
            'title' => $title, 'url' => $url, 'sort_order' => $order, 'is_active' => $active,
        ]);
    }

    public function test_the_accounts_panel_lists_each_active_link_with_its_platform_and_display_url(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/its_uriel26', 'X', true, 0);
        $this->link($card, 'https://www.linkedin.com/in/uriel-papa/', 'LinkedIn', true, 1);

        $this->get('/n/'.$card->public_id)
            ->assertOk()
            ->assertSee('Accounts')
            ->assertSee('x.com/its_uriel26')
            ->assertSee('LinkedIn')
            ->assertSee('linkedin.com/in/uriel-papa');
    }

    public function test_an_inactive_link_is_not_listed(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/shown', 'X', true, 0);
        $this->link($card, 'https://x.com/hidden', 'X', false, 1);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('x.com/shown', $body);
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

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('Website', $body);
        $this->assertStringContainsString('viteseo.ph/services', $body);
    }

    /**
     * The icon is the only signal a visitor has about where a row leads, so a
     * host that merely contains a brand name must not wear that brand's mark.
     * LinkPlatform has its own unit test for this; this one pins that the view
     * actually routes through it.
     */
    public function test_a_spoofed_host_does_not_get_a_brand_icon_on_the_page(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://linkedin.com.attacker.tld/in/uriel');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringContainsString('Website', $body);
        $this->assertStringNotContainsString('LinkedIn', $body, 'a hostile host must not borrow the LinkedIn label');
    }

    public function test_the_icon_row_carries_brand_links_only(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://x.com/its_uriel26', 'X', true, 0);
        $this->link($card, 'https://viteseo.ph/services', 'Site', true, 1);

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        // One mark in the icon row (X); the plain website appears only in the
        // panel below. A row of identical globes is noise, not navigation.
        // The icon row contains only <a> and <svg>, so the first closing div is its own.
        $this->assertSame(1, preg_match('#<div class="iconrow">(.*?)</div>#s', $body, $m), 'icon row not found');
        $this->assertSame(1, substr_count($m[1], '<svg'), 'the icon row must carry brand marks only');

        // Both links are still reachable from the panel.
        $this->assertStringContainsString('x.com/its_uriel26', $body);
        $this->assertStringContainsString('viteseo.ph/services', $body);
    }

    public function test_a_link_url_is_escaped_in_the_href(): void
    {
        $card = $this->profileCard();
        $this->link($card, 'https://example.com/"><script>alert(1)</script>');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=NfcProfileTest`
Expected: FAIL — no `Accounts` heading and no link content on the page.

- [ ] **Step 3: Pass the links to the view**

In `showProfile()`:

```php
        return $this->noStore(response()->view('public.nfc-profile', [
            'card'    => $card,
            'profile' => $profile,
            'links'   => $card->activeNfcLinks()->get(),
        ]));
```

- [ ] **Step 4: Render both lists**

Replace the `{{-- icon row: Task 9 --}}` marker in `resources/views/public/nfc-profile.blade.php`:

```blade
      @php
        $branded = $links->filter(fn ($l) => \App\Services\LinkPlatform::forUrl($l->url) !== 'web');
      @endphp
      @if ($branded->isNotEmpty())
        <div class="iconrow">
          @foreach ($branded as $link)
            @php $platform = \App\Services\LinkPlatform::forUrl($link->url); @endphp
            <a href="{{ $link->url }}" rel="noopener noreferrer nofollow ugc" target="_blank"
               aria-label="{{ \App\Services\LinkPlatform::label($platform) }}">
              <x-platform-icon :platform="$platform" />
            </a>
          @endforeach
        </div>
      @endif
```

Replace the `{{-- accounts card: Task 9 --}}` marker:

```blade
  @if ($links->isNotEmpty())
    <section class="card card-pad">
      <h2>Accounts</h2>
      {{-- No verification badges anywhere: nothing here proves account
           ownership, and a checkmark would assert a guarantee that does not
           exist. Hence "Accounts" rather than a verified-profile framing. --}}
      @foreach ($links as $link)
        @php $platform = \App\Services\LinkPlatform::forUrl($link->url); @endphp
        <div class="acct">
          <span class="acct-ico"><x-platform-icon :platform="$platform" /></span>
          <div>
            <div class="acct-name">{{ \App\Services\LinkPlatform::label($platform) }}</div>
            <a class="acct-url" href="{{ $link->url }}" rel="noopener noreferrer nofollow ugc" target="_blank">{{ \App\Services\LinkPlatform::displayUrl($link->url) }}</a>
          </div>
        </div>
      @endforeach
    </section>
  @endif
```

`rel="noopener noreferrer nofollow ugc"` matches the existing contact view's treatment of cardholder-supplied URLs.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=NfcProfileTest`
Expected: PASS.

Run: `php artisan test`
Expected: PASS, everything.

Then prove the spoofing test bites at the view level: temporarily replace `LinkPlatform::forUrl($link->url)` in the accounts loop with the literal `'linkedin'` and confirm `test_a_spoofed_host_does_not_get_a_brand_icon_on_the_page` fails. Restore it.

- [ ] **Step 6: Commit**

```bash
git add resources/views/public/nfc-profile.blade.php app/Http/Controllers/Public/NfcController.php tests/Feature/NfcProfileTest.php
git commit -m "feat: render the accounts panel and the social icon row

Both read the same active links Links mode serves, so switching modes
loses nothing. The icon row carries brand links only; unrecognised hosts
appear in the panel as a website with a globe."
```

---

## Definition of done

- `php artisan test` passes with no warnings, deprecations or skipped tests.
- A card put into Profile mode with a `display_name` renders the profile page; without one it renders the not-ready page, never the links setup form.
- The dashboard shows `Profile Page · Active` for a configured profile card and `Profile Page · Unconfigured` for an empty one, with an em dash in the NFC Links column, and its query count is unchanged by row count.
- `resetNfc` and `resetCard` clear the profile row and delete the avatar file.
- No `<script>` anywhere in `resources/views/public/nfc-profile.blade.php`.
- `rg -n 'card_number' resources/views/public/` returns nothing.
- `rg -n 'ContactPhotoStorage' --glob '!vendor'` returns nothing.

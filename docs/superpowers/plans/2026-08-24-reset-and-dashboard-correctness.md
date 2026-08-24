# Reset and Dashboard Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make both reset actions clear every NFC mode's data and make the admin dashboard report status per mode, so the fifth mode (Profile) is not built on top of two known data-leak bugs.

**Architecture:** The list of "what counts as a card's NFC payload" currently exists twice inside `Admin\CardController` and is wrong in both places. It moves into a single `CardResetService`, which the two controller actions call. Status logic exists as a PHP method (`Card::nfcModeIsConfigured()`, already written but unused) and needs a SQL twin for counting across thousands of rows; both twins get paired tests that fail if they ever disagree.

**Tech Stack:** Laravel 12, PHPUnit (class-style tests, not Pest), Eloquent, SQLite in-memory for tests, GD for existing image handling.

## Global Constraints

- This is increment 1 of 6 from `docs/superpowers/specs/2026-08-24-nfc-profile-mode-design.md`. It must not reference `nfc_profiles`, `NfcProfile`, `NfcMode::Profile` or anything else from later increments — none of them exist yet.
- Tests are PHPUnit classes extending `Tests\TestCase` with `use RefreshDatabase`, matching `tests/Feature/NfcModesTest.php`. Data providers use `#[\PHPUnit\Framework\Attributes\DataProvider('methodName')]`.
- Test database is in-memory SQLite, configured in `phpunit.xml`. **If a test run fails with `SQLSTATE[HY000] [1045] Access denied for user 'nfc_user'`, the config cache is stale — run `php artisan config:clear` and retry.** This has already bitten this project once.
- No new Composer dependencies.
- Columns outside `Card::$fillable` are written with `forceFill([...])->save()`, matching every existing call site.
- `nfc_mode` is `string(20) not null default 'links'` and indexed. It is never NULL, so no null-handling is needed when querying it.
- `nfc_logo_path` is nulled by the reset actions but is never written by any upload path in the codebase. There is no file to delete for it; do not invent one.
- Admin routes already exist and do not change: `DELETE /admin/cards/{card}/nfc` → `resetNfc`, `DELETE /admin/cards/{card}/reset` → `resetCard`.
- Run the full suite before the final commit of each task, not just the filtered test.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Services/CardResetService.php` (create) | The single definition of what clearing a card means. Both reset actions delegate here. Later increments add their mode to this one file. |
| `app/Models/Card.php` (modify) | Gains a SQL scope twin of `nfcModeIsConfigured()`, and teaches `activeNfcLinkCount()` to honour the dashboard's preloaded aggregate. |
| `app/Models/NfcContactProfile.php` (modify) | Gains a SQL scope twin of `isMeaningful()`. |
| `app/Http/Controllers/Admin/CardController.php` (modify) | Both reset methods shrink to a service call plus a redirect. |
| `app/Http/Controllers/Admin/DashboardController.php` (modify) | Query eager-loads what the status check needs; `stats()` counts per mode. |
| `resources/views/admin/dashboard.blade.php` (modify) | NFC status cell shows mode plus configured state. |
| `tests/Feature/CardResetTest.php` (create) | Reset behaviour, including the post-reset public-page regressions. |
| `tests/Feature/DashboardStatusTest.php` (create) | Status scopes, twin-agreement tests, stat counters, query-count ceiling. |

---

### Task 1: `activeNfcLinkCount()` honours a preloaded aggregate

The dashboard already selects `active_link_count` as a correlated subquery for the whole page. `activeNfcLinkCount()` ignores it and runs its own query per card, so once the dashboard starts calling `nfcModeIsConfigured()` (Task 8) every Links-mode row would add a query. Fixing the accessor first means the dashboard change is free.

**Files:**
- Modify: `app/Models/Card.php:102-107`
- Test: `tests/Feature/DashboardStatusTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `Card::activeNfcLinkCount(): int` — unchanged signature, now returns the value of the `active_link_count` attribute when that attribute is present on the model.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardStatusTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Card;
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_preloaded_active_link_count_is_used_without_another_query`
Expected: FAIL — asserts `0` queries but gets `1`, because the accessor ignores the attribute and runs `select count(*)`.

- [ ] **Step 3: Write minimal implementation**

Replace `activeNfcLinkCount()` in `app/Models/Card.php`:

```php
    public function activeNfcLinkCount(): int
    {
        // The dashboard selects this aggregate once for the whole page as a
        // correlated subquery. Honouring it here is what keeps a 25-row table at
        // a constant query count instead of one count per row.
        if (array_key_exists('active_link_count', $this->attributes)) {
            return (int) $this->attributes['active_link_count'];
        }

        return $this->relationLoaded('activeNfcLinks')
            ? $this->activeNfcLinks->count()
            : $this->activeNfcLinks()->count();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardStatusTest`
Expected: PASS, 2 tests.

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Card.php tests/Feature/DashboardStatusTest.php
git commit -m "fix: honour the preloaded active_link_count on the dashboard

The accessor ignored the correlated subquery the dashboard already selects,
so any caller on a paginated page paid one count query per row."
```

---

### Task 2: `NfcContactProfile::scopeMeaningful()` as a tested SQL twin

The dashboard has to count configured Contact-mode cards without instantiating every profile, so `isMeaningful()` needs a SQL equivalent. Two definitions of the same rule drift silently, so they ship with a test that compares them over the same fixtures.

`isMeaningful()` is `hasName()`, which is true when any of `first_name`, `middle_name`, `last_name`, `suffix` or `company` is a non-empty string.

**Files:**
- Modify: `app/Models/NfcContactProfile.php:127-131` (add the scope after `isMeaningful()`)
- Test: `tests/Feature/DashboardStatusTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `NfcContactProfile::scopeMeaningful(Builder $query): Builder`, called as `NfcContactProfile::query()->meaningful()` or inside `whereHas('contactProfile', fn ($q) => $q->meaningful())`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardStatusTest.php`:

```php
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
```

Add `use App\Models\NfcContactProfile;` to the imports.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_meaningful_scope_agrees_with_is_meaningful`
Expected: FAIL — `BadMethodCallException: Call to undefined method App\Models\NfcContactProfile::meaningful()`.

- [ ] **Step 3: Write minimal implementation**

In `app/Models/NfcContactProfile.php`, add `use Illuminate\Database\Eloquent\Builder;` to the imports, then add after `isMeaningful()`:

```php
    /**
     * SQL twin of isMeaningful().
     *
     * The dashboard counts configured cards across the whole table and cannot
     * instantiate a model per row, so this rule exists in both PHP and SQL.
     * test_meaningful_scope_agrees_with_is_meaningful is what keeps the two
     * from drifting apart.
     *
     * A NULL column does not satisfy `!= ''` in SQL, which is exactly the
     * wanted behaviour: unset is not meaningful.
     */
    public function scopeMeaningful(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'company'] as $column) {
                $q->orWhere($column, '!=', '');
            }
        });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardStatusTest`
Expected: PASS, 11 tests (2 from Task 1 plus 9 provider cases).

- [ ] **Step 5: Commit**

```bash
git add app/Models/NfcContactProfile.php tests/Feature/DashboardStatusTest.php
git commit -m "feat: add a tested SQL twin of NfcContactProfile::isMeaningful

The dashboard needs to count configured contact cards in SQL. The paired test
fails if the two definitions of 'meaningful' ever diverge."
```

---

### Task 3: `Card::scopeWhereNfcModeConfigured()` as a tested SQL twin

Same problem one level up: `nfcModeIsConfigured()` answers the question per model, and `stats()` needs it as a countable query. The agreement test runs every mode in both configured and unconfigured states through both implementations.

**Files:**
- Modify: `app/Models/Card.php` (add the scope after `nfcModeIsConfigured()`, around line 81)
- Test: `tests/Feature/DashboardStatusTest.php`

**Interfaces:**
- Consumes: `NfcContactProfile::scopeMeaningful()` from Task 2.
- Produces: `Card::scopeWhereNfcModeConfigured(Builder $query, bool $configured = true): Builder`, called as `Card::query()->whereNfcModeConfigured()` and `Card::query()->whereNfcModeConfigured(false)`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardStatusTest.php`:

```php
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

    // As delivered, the two tests below were generalised into a data provider
    // covering every branch, not just contact-on-phone: an active link on a
    // contact-mode card, a phone number on an email-mode card, an email address
    // on a links-mode card, and empty-string (not just NULL) phone and email
    // columns. Each case was mutation-tested by deleting the corresponding
    // `where('nfc_mode', ...)` predicate and confirming a failure. Do not
    // narrow this back to one case — the mode predicates are what stop another
    // mode's leftover data from reading as configured, and only these fixtures
    // hold them in place.
    public function test_another_modes_data_does_not_configure_the_current_mode(): void
    {
        // A phone-mode card holding only contact details is not configured: the
        // permanent URL serves the phone action, and there is no phone number.
        $card = $this->card();
        $card->contactProfile()->save($card->contactProfile()->make(['first_name' => 'John']));
        $card->forceFill(['nfc_mode' => 'phone'])->save();

        $this->assertFalse($card->fresh()->nfcModeIsConfigured());
        $this->assertFalse(Card::query()->whereNfcModeConfigured()->whereKey($card->getKey())->exists());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_configured_scope_agrees_with_nfc_mode_is_configured`
Expected: FAIL — `BadMethodCallException: Call to undefined method App\Models\Card::whereNfcModeConfigured()`.

- [ ] **Step 3: Write minimal implementation**

In `app/Models/Card.php`, add `use Illuminate\Database\Eloquent\Builder;` to the imports, then add after `nfcModeIsConfigured()`:

```php
    /**
     * SQL twin of nfcModeIsConfigured().
     *
     * The dashboard needs this as a countable query, and each mode stores its
     * payload somewhere different, so the branch has to be reproduced in SQL.
     * test_configured_scope_agrees_with_nfc_mode_is_configured is what keeps
     * the two implementations honest.
     *
     * When a mode is added, both this scope and nfcModeIsConfigured() must
     * learn about it, or a card actively serving that mode reads as
     * "Unconfigured" on the dashboard.
     */
    public function scopeWhereNfcModeConfigured(Builder $query, bool $configured = true): Builder
    {
        $matches = function (Builder $q) {
            $q->where(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Links->value)
                    ->whereHas('nfcLinks', fn ($l) => $l->where('is_active', true)))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Contact->value)
                    ->whereHas('contactProfile', fn ($p) => $p->meaningful()))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Phone->value)
                    ->whereRaw("coalesce(nfc_phone_number, '') != ''"))
              ->orWhere(fn (Builder $w) => $w
                    ->where('nfc_mode', NfcMode::Email->value)
                    ->whereRaw("coalesce(nfc_email_address, '') != ''"));
        };

        return $configured ? $query->where($matches) : $query->whereNot($matches);
    }
```

`nfc_phone_number` and `nfc_email_address` are nullable, and `coalesce` is load-bearing rather than defensive. A bare `where('nfc_phone_number', '!=', '')` gets the positive case right by accident — SQL evaluates `NULL != ''` to UNKNOWN, and a WHERE clause drops UNKNOWN rows, which happens to match the PHP method. But `NOT UNKNOWN` is still UNKNOWN, so the negated form drops the same rows instead of selecting them, and `whereNfcModeConfigured(false)` stops being the complement of `whereNfcModeConfigured()`. Coercing NULL to `''` first makes the predicate two-valued, so the negation is exact. This mirrors the PHP side's `(string) $this->nfc_phone_number !== ''` cast, and it is the same reason Task 2's scope wraps its columns in `coalesce`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardStatusTest`
Expected: PASS, 21 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Card.php tests/Feature/DashboardStatusTest.php
git commit -m "feat: add a tested SQL twin of Card::nfcModeIsConfigured

Each mode stores its payload in a different place, so status has to be
expressed in SQL for the dashboard to count it. The paired test asserts the
scope and its inverse are exact complements of the PHP method."
```

---

### Task 4: `CardResetService::resetNfcSide()` clears every mode

Today `resetNfc` deletes links and branding only, then reports "NFC reset. The next tap will show the setup page." On a Contact-mode card that message is false: the profile, the mode and the phone and email columns all survive, so the next tap still serves the previous holder's details.

**Files:**
- Create: `app/Services/CardResetService.php`
- Test: `tests/Feature/CardResetTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `CardResetService::resetNfcSide(Card $card): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CardResetTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\NfcMode;
use App\Models\Card;
use App\Models\User;
use App\Services\CardGenerationService;
use App\Services\CardResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CardResetTest extends TestCase
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

    /**
     * A card carrying data for every mode at once, plus branding, a QR
     * destination and a stored contact photo. Mode switching is lossless by
     * design, so this is a realistic state for a re-issued card.
     */
    private function fullyPopulatedCard(): Card
    {
        $card = $this->card();

        $card->nfcLinks()->create([
            'title' => 'Site', 'url' => 'https://example.com/s', 'sort_order' => 0, 'is_active' => true,
        ]);

        Storage::disk('public')->put('contact-photos/old.jpg', 'not-really-a-jpeg');

        $profile = $card->contactProfile()->make([
            'first_name' => 'John', 'last_name' => 'Doe', 'company' => 'Acme Ltd',
        ]);
        $profile->photo_path = 'contact-photos/old.jpg';
        $card->contactProfile()->save($profile);

        $card->forceFill([
            'nfc_mode'          => 'contact',
            'nfc_phone_number'  => '+639171234567',
            'nfc_email_address' => 'hi@example.com',
            'nfc_email_subject' => 'Hello',
            'nfc_email_body'    => 'Body',
            'nfc_page_title'    => 'Acme',
            'nfc_page_subtitle' => 'Links',
            'nfc_logo_path'     => 'logos/acme.png',
            // Fixed, historical, and deliberately not now(): a fixture value
            // that coincides with what a bug would write proves nothing. If the
            // service ever overwrote qr_configured_at with now(), a now()
            // fixture would match it to the second and the guard below would
            // pass while the QR side had in fact been touched.
            'nfc_configured_at' => '2024-06-15 09:30:00',
            'qr_destination'    => 'https://example.com/qr',
            'qr_configured_at'  => '2024-06-15 09:30:00',
        ])->save();

        return $card->fresh();
    }

    private function assertNfcSideIsClear(Card $card): void
    {
        $card->refresh();

        $this->assertSame(0, $card->nfcLinks()->count(), 'links');
        $this->assertNull($card->contactProfile, 'contact profile');
        $this->assertNull($card->nfc_phone_number, 'phone');
        $this->assertNull($card->nfc_email_address, 'email address');
        $this->assertNull($card->nfc_email_subject, 'email subject');
        $this->assertNull($card->nfc_email_body, 'email body');
        $this->assertNull($card->nfc_page_title, 'page title');
        $this->assertNull($card->nfc_page_subtitle, 'page subtitle');
        $this->assertNull($card->nfc_logo_path, 'logo path');
        $this->assertNull($card->nfc_configured_at, 'configured timestamp');
        $this->assertSame(NfcMode::Links, $card->nfcMode(), 'mode returns to links');
    }

    public function test_resetting_the_nfc_side_clears_every_modes_payload(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        app(CardResetService::class)->resetNfcSide($card);

        $this->assertNfcSideIsClear($card);
    }

    public function test_resetting_the_nfc_side_deletes_the_contact_photo_file(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        Storage::disk('public')->assertExists('contact-photos/old.jpg');

        app(CardResetService::class)->resetNfcSide($card);

        Storage::disk('public')->assertMissing('contact-photos/old.jpg');
    }

    public function test_resetting_the_nfc_side_leaves_the_qr_alone(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $originalQrConfiguredAt = $card->qr_configured_at->format('Y-m-d H:i:s');

        app(CardResetService::class)->resetNfcSide($card);
        $card->refresh();

        $this->assertSame('https://example.com/qr', $card->qr_destination);

        // Exact equality, not assertNotNull: "untouched" has to mean untouched,
        // and a service that rewrote this to now() would satisfy a null check.
        $this->assertSame($originalQrConfiguredAt, $card->qr_configured_at->format('Y-m-d H:i:s'));
    }

    public function test_resetting_the_nfc_side_keeps_the_permanent_identity(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $number = $card->card_number;
        $publicId = $card->public_id;
        $nfcUrl = $card->nfcUrl();
        $qrUrl = $card->qrUrl();

        app(CardResetService::class)->resetNfcSide($card);
        $card->refresh();

        $this->assertSame($number, $card->card_number);
        $this->assertSame($publicId, $card->public_id);
        $this->assertSame($nfcUrl, $card->nfcUrl());
        $this->assertSame($qrUrl, $card->qrUrl());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CardResetTest`
Expected: FAIL — `Target class [App\Services\CardResetService] does not exist.`

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/CardResetService.php`:

```php
<?php

namespace App\Services;

use App\Enums\NfcMode;
use App\Models\Card;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Clearing a card's configuration.
 *
 * Both reset actions live here so that "what counts as the NFC payload" is one
 * list in one file. It used to be spelled out twice inside CardController and
 * was incomplete in both copies: a Contact-mode card reported as reset kept
 * serving the previous holder's name.
 *
 * When a mode is added, this file is the single place that has to learn about
 * it. A mode missed here means a card the admin was told is blank still
 * answers with someone else's data.
 */
class CardResetService
{
    /**
     * Clears everything the permanent NFC URL can serve, in every mode, and
     * returns the card to Links mode. The QR destination is untouched.
     *
     * Deliberately not surgical. The per-mode delete actions already cover
     * "remove just this one thing", which is what leaves this action free to
     * mean exactly what its confirmation message says.
     */
    public function resetNfcSide(Card $card): void
    {
        // Read before the transaction: the model is gone by the time it commits.
        $photoPath = $card->contactProfile?->photo_path;

        DB::transaction(function () use ($card) {
            $card->nfcLinks()->delete();

            if ($profile = $card->contactProfile) {
                $profile->delete();
            }

            $card->forceFill([
                'nfc_mode'          => NfcMode::Links,
                'nfc_configured_at' => null,
                'nfc_phone_number'  => null,
                'nfc_email_address' => null,
                'nfc_email_subject' => null,
                'nfc_email_body'    => null,
                'nfc_page_title'    => null,
                'nfc_page_subtitle' => null,
                'nfc_logo_path'     => null,
            ])->save();
        });

        // Delete the photo only after the transaction commits. Filesystem
        // deletes do not roll back; a file removed while the profile row
        // survives would show a broken image on a live contact page. An
        // orphaned file after a successful reset costs disk space only.
        if ($photoPath) {
            Storage::disk('public')->delete($photoPath);
        }

        // The in-memory model may still hold the profile we just deleted.
        $card->unsetRelation('contactProfile');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CardResetTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CardResetService.php tests/Feature/CardResetTest.php
git commit -m "feat: add CardResetService that clears every NFC mode

Resetting NFC previously deleted links and branding only, so a contact-mode
card reported as reset kept serving the previous holder's name, phone and
email. One list in one file, so a new mode cannot be half-forgotten."
```

---

### Task 5: `CardResetService::resetEverything()` adds the QR

**Files:**
- Modify: `app/Services/CardResetService.php`
- Test: `tests/Feature/CardResetTest.php`

**Interfaces:**
- Consumes: `CardResetService::resetNfcSide(Card $card): void` from Task 4, plus the test helpers `fullyPopulatedCard(): Card` and `assertNfcSideIsClear(Card $card): void` already defined in `CardResetTest` by Task 4.
- Produces: `CardResetService::resetEverything(Card $card): void`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CardResetTest.php`:

```php
    public function test_resetting_everything_clears_the_nfc_side_and_the_qr(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        app(CardResetService::class)->resetEverything($card);

        $this->assertNfcSideIsClear($card);
        $this->assertNull($card->qr_destination, 'qr destination');
        $this->assertNull($card->qr_configured_at, 'qr timestamp');
    }

    public function test_resetting_everything_keeps_the_permanent_identity(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();
        $publicId = $card->public_id;
        $nfcUrl = $card->nfcUrl();
        $qrUrl = $card->qrUrl();

        app(CardResetService::class)->resetEverything($card);
        $card->refresh();

        $this->assertSame($publicId, $card->public_id);
        $this->assertSame($nfcUrl, $card->nfcUrl());
        $this->assertSame($qrUrl, $card->qrUrl());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_resetting_everything_clears_the_nfc_side_and_the_qr`
Expected: FAIL — `Call to undefined method App\Services\CardResetService::resetEverything()`.

- [ ] **Step 3: Write minimal implementation**

Add to `app/Services/CardResetService.php`:

```php
    /**
     * resetNfcSide() plus the QR destination.
     *
     * Passes the two QR columns to the shared reset helper so they are cleared
     * in the same transaction as the NFC payload — no nested transactions,
     * no duplicated NFC column list.
     */
    public function resetEverything(Card $card): void
    {
        $this->reset($card, [
            'qr_destination'   => null,
            'qr_configured_at' => null,
        ]);
    }
```

This requires restructuring Task 4's method rather than wrapping it. Wrapping does not work: `resetNfcSide()` deletes the photo file straight after its own transaction, and under nesting that inner commit is only a savepoint release, so a failing QR save would roll the profile row back with the file already gone — reinstating the bug Task 4 exists to fix, one level up.

So both public methods become one-line delegations to a private helper that takes the extra columns:

```php
    private function reset(Card $card, array $extraColumns = []): void
    {
        $photoPath = $card->contactProfile?->photo_path;

        DB::transaction(function () use ($card, $extraColumns) {
            $card->nfcLinks()->delete();

            if ($profile = $card->contactProfile) {
                $profile->delete();
            }

            $card->forceFill(array_merge([
                'nfc_mode'          => NfcMode::Links,
                'nfc_configured_at' => null,
                'nfc_phone_number'  => null,
                'nfc_email_address' => null,
                'nfc_email_subject' => null,
                'nfc_email_body'    => null,
                'nfc_page_title'    => null,
                'nfc_page_subtitle' => null,
                'nfc_logo_path'     => null,
            ], $extraColumns))->save();
        });

        if ($photoPath) {
            Storage::disk('public')->delete($photoPath);
        }

        $card->unsetRelation('contactProfile');
    }
```

One transaction, one post-commit file deletion, and the NFC column list written once — which is the property that matters when a fifth mode is added, since one edit then covers both entry points.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CardResetTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CardResetService.php tests/Feature/CardResetTest.php
git commit -m "feat: add CardResetService::resetEverything

Composed from resetNfcSide rather than repeating the column list, so a mode
added there is covered here without a second edit."
```

---

### Task 6: Both admin reset actions delegate to the service

**Files:**
- Modify: `app/Http/Controllers/Admin/CardController.php:147-167` (`resetNfc`) and `:400-421` (`resetCard`)
- Test: `tests/Feature/CardResetTest.php`

**Interfaces:**
- Consumes: `CardResetService::resetNfcSide()` and `::resetEverything()` from Tasks 4-5; the test helpers `fullyPopulatedCard()`, `assertNfcSideIsClear()` and `admin()` from `CardResetTest` as written in Task 4.
- Produces: no new signatures. `resetNfc` and `resetCard` keep their routes, verbs and names.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CardResetTest.php`:

```php
    public function test_admin_nfc_reset_endpoint_clears_every_mode(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/nfc')
            ->assertRedirect();

        $this->assertNfcSideIsClear($card);
        $this->assertSame('https://example.com/qr', $card->qr_destination, 'QR is not part of an NFC reset');
        Storage::disk('public')->assertMissing('contact-photos/old.jpg');
    }

    public function test_admin_full_reset_endpoint_clears_the_qr_too(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->actingAs($this->admin())
            ->delete('/admin/cards/'.$card->public_id.'/reset')
            ->assertRedirect();

        $this->assertNfcSideIsClear($card);
        $this->assertNull($card->qr_destination);
        $this->assertNull($card->qr_configured_at);
    }

    public function test_reset_endpoints_require_authentication(): void
    {
        $card = $this->card();

        // Guests land on /admin/login, not Laravel's default /login: there is
        // no /login route in this app. AdminTest asserts the same target.
        $this->delete('/admin/cards/'.$card->public_id.'/nfc')->assertRedirect('/admin/login');
        $this->delete('/admin/cards/'.$card->public_id.'/reset')->assertRedirect('/admin/login');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_admin_nfc_reset_endpoint_clears_every_mode`
Expected: FAIL — `nfc_phone_number` is still `+639171234567` and `contactProfile` is not null, because the controller still has its own incomplete column list.

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Admin/CardController.php`, add the import:

```php
use App\Services\CardResetService;
```

Add the dependency to the existing constructor at `app/Http/Controllers/Admin/CardController.php:26-29`, which currently reads:

```php
    public function __construct(
        private readonly CardGenerationService $cards,
        private readonly QrCodeService $qr,
    ) {}
```

It becomes:

```php
    public function __construct(
        private readonly CardGenerationService $cards,
        private readonly QrCodeService $qr,
        private readonly CardResetService $reset,
    ) {}
```

Replace `resetNfc()`:

```php
    /**
     * DELETE /admin/cards/{card}/nfc
     *
     * Clears the whole NFC side in every mode and returns the card to Links
     * mode, so the confirmation message below is true regardless of what the
     * card was serving. QR is untouched. The surgical "remove just this" case
     * is served by the per-mode delete actions.
     */
    public function resetNfc(Card $card): RedirectResponse
    {
        $this->reset->resetNfcSide($card);

        return back()->with('status', 'NFC reset. Every mode has been cleared and the next tap will show the setup page.');
    }
```

Replace `resetCard()`:

```php
    /**
     * DELETE /admin/cards/{card}/reset
     * Clears all configuration but KEEPS card_number, public_id and therefore
     * both permanent URLs - the physical card stays valid. The activation PIN is
     * deliberately preserved unless the admin regenerates it explicitly.
     */
    public function resetCard(Card $card): RedirectResponse
    {
        $this->reset->resetEverything($card);

        return back()->with('status', 'Card reset. Both sides are blank. Card number, public ID, both permanent URLs and the activation PIN are unchanged.');
    }
```

Keep the `use Illuminate\Support\Facades\DB;` import — the link reordering method around line 130 still uses `DB::`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CardResetTest`
Expected: PASS, 11 tests.

Both endpoint tests assert the exact status message string. The message is the promise the admin acts on when re-issuing a card, so it is held by a test rather than left to drift. The full-reset message names the activation PIN as surviving, and that test asserts `activation_pin_hash` is unchanged, so the message and the behaviour cannot diverge.

Run: `php artisan test`
Expected: PASS. `AdminTest::test_nfc_reset_does_not_affect_qr` and `AdminTest::test_full_reset_preserves_permanent_identity` both already pass under the new behaviour — they assert on links, branding, QR and identity, all of which are unchanged — and neither asserts on the status message text. No edits to `AdminTest.php` are expected.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/CardController.php tests/Feature/CardResetTest.php
git commit -m "fix: make both admin reset actions clear every NFC mode

resetNfc left the contact profile, phone, email and mode untouched, and
resetCard did the same while claiming the card was fully reset. Both now
delegate to CardResetService."
```

---

### Task 7: Public-page regressions after a reset

The bug that matters to a cardholder is not the column state, it is that a reset card keeps answering with the previous holder's data. These tests assert the observable behaviour through the public routes, so a future refactor of the service cannot quietly reintroduce it.

**Files:**
- Test: `tests/Feature/CardResetTest.php`

**Interfaces:**
- Consumes: the admin reset endpoints wired in Task 6; the test helpers `fullyPopulatedCard()` and `admin()` from `CardResetTest` as written in Task 4.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CardResetTest.php`:

```php
    public function test_a_reset_card_no_longer_serves_the_previous_holders_contact_page(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        // Before: the contact page is live.
        $this->get('/n/'.$card->public_id)->assertOk()->assertSee('John Doe');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');

        $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

        $this->assertStringNotContainsString('John Doe', $body);
        $this->assertStringNotContainsString('Acme Ltd', $body);
        $this->assertStringNotContainsString('+639171234567', $body);
        $this->assertStringNotContainsString('hi@example.com', $body);
    }

    public function test_a_reset_card_no_longer_returns_the_previous_holders_vcard(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        // The route is /n/{card}/contact.vcf, named nfc.vcard. NfcController
        // aborts 404 unless the card is in Contact mode with a named profile,
        // so a reset card must stop returning a file at all.
        $this->get('/n/'.$card->public_id.'/contact.vcf')->assertOk();

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');

        $this->get('/n/'.$card->public_id.'/contact.vcf')->assertNotFound();
    }

    public function test_a_reset_card_stops_redirecting_to_the_previous_qr_destination(): void
    {
        Storage::fake('public');
        $card = $this->fullyPopulatedCard();

        $this->get('/q/'.$card->public_id)->assertRedirect('https://example.com/qr');

        $this->actingAs($this->admin())->delete('/admin/cards/'.$card->public_id.'/reset');

        // An unconfigured QR renders the public.qr-setup view with a 200; it
        // does not 404. See QrController::show.
        $this->get('/q/'.$card->public_id)
            ->assertOk()
            ->assertDontSee('https://example.com/qr');
    }
```

Two corrections were made to the above while implementing, both because the test as drafted could not fail:

1. The contact-page test's phone and email assertions named `nfc_phone_number` and `nfc_email_address`, but `public/nfc-contact.blade.php` renders the *profile's* phone and email columns, never the card's. The test now sets `mobile_phone` and `personal_email` on the profile and asserts them visible before the reset, so every value asserted as gone afterwards is one the page demonstrably printed. The card-column phone and email got their own tests through Phone and Email mode, which are the pages that print them.

2. All three mode tests re-select the mode through `PATCH /admin/cards/{card}/nfc-mode` after the reset. Without that, resetting `nfc_mode` to `Links` hides every other mode's data behind the setup page, so a reset that flipped the mode and left the data behind would pass. Re-selecting the mode is also the real re-issue flow: reset, then configure for the new holder.

- [ ] **Step 2: Run tests to verify they pass**

These describe behaviour that Task 6 already delivered, so they should pass immediately. That is the point: they are regression locks, not drivers.

Run: `php artisan test --filter=CardResetTest`
Expected: PASS, 16 tests.

If any fail, the reset is still leaking. Fix `CardResetService`, not the test.

Then prove each lock can fail. Removing the profile delete from `CardResetService::reset()` must fail the contact-page test; dropping `nfc_phone_number` from its column list must fail the phone test; dropping `nfc_email_address` must fail the email test. A lock that cannot fail is decoration.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/CardResetTest.php
git commit -m "test: lock the post-reset public behaviour

Asserts through the public routes that a reset card serves no part of the
previous holder's contact page, vCard or QR destination."
```

---

### Task 8: Dashboard NFC status is mode-aware

The dashboard derives NFC status from active link count alone, so every card serving a contact card, phone number or email address displays "Unconfigured". `Card::nfcModeIsConfigured()` has existed unused since the modes migration; this task puts it to work.

**Files:**
- Modify: `app/Http/Controllers/Admin/DashboardController.php:16-40`
- Modify: `resources/views/admin/dashboard.blade.php:66-84`
- Test: `tests/Feature/DashboardStatusTest.php`

**Interfaces:**
- Consumes: `Card::activeNfcLinkCount()` (Task 1), `Card::nfcModeIsConfigured()` (existing), `NfcMode::label()` (existing), and the test helpers `cardInMode(string $mode, bool $configured): Card` and `admin(): User` already defined in `DashboardStatusTest` by Tasks 1 and 3.
- Produces: the test helper `assertNfcBadge(string $expected): void`, reused by no later task.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardStatusTest.php`:

```php
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

    public function test_dashboard_shows_active_for_a_configured_contact_card(): void
    {
        $this->cardInMode('contact', true);

        $this->assertNfcBadge('Digital Contact Card &middot; Active');
    }

    public function test_dashboard_shows_active_for_a_configured_phone_card(): void
    {
        $this->cardInMode('phone', true);

        $this->assertNfcBadge('Phone / Call &middot; Active');
    }

    public function test_dashboard_shows_active_for_a_configured_email_card(): void
    {
        $this->cardInMode('email', true);

        $this->assertNfcBadge('Email &middot; Active');
    }

    public function test_dashboard_shows_unconfigured_for_an_empty_contact_card(): void
    {
        $this->cardInMode('contact', false);

        $this->assertNfcBadge('Digital Contact Card &middot; Unconfigured');
    }

    public function test_dashboard_link_count_column_is_blank_outside_links_mode(): void
    {
        $this->cardInMode('contact', true);

        $body = $this->actingAs($this->admin())->get('/admin')->assertOk()->getContent();

        // A literal 0 next to a live contact card is what made the old
        // dashboard read as broken.
        $this->assertStringContainsString('data-label="NFC Links" class="mono muted">—<', $body);
    }
```

The dashboard is `GET /admin`, named `admin.dashboard` (`routes/web.php:55`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_dashboard_shows_active_for_a_configured_contact_card`
Expected: FAIL — the page shows "Unconfigured" because the status cell reads `active_link_count`, which is 0 for a contact card.

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/Admin/DashboardController.php`, replace the query construction in `index()`:

```php
        $activeLinkCount = DB::raw(
            '(select count(*) from nfc_links where nfc_links.card_id = cards.id and nfc_links.is_active = 1) as active_link_count'
        );

        $query = Card::query()
            ->select('cards.*')
            ->addSelect($activeLinkCount)
            // Contact-mode status needs the name columns and nothing else.
            // Selecting a subset keeps a 25-row page at a constant query count
            // without hydrating whole profiles, including notes and addresses.
            ->with(['contactProfile:id,card_id,first_name,middle_name,last_name,suffix,company']);
```

In `resources/views/admin/dashboard.blade.php`, replace lines 66-84:

```blade
    @forelse ($cards as $card)
      @php
        $n       = (int) $card->active_link_count;
        $mode    = $card->nfcMode();
        $nfcOn   = $card->nfcModeIsConfigured();
        $isLinks = $mode === \App\Enums\NfcMode::Links;
      @endphp
      <tr>
        <td data-label="Card Number"><strong>{{ $card->card_number }}</strong></td>
        <td data-label="Public ID"><code>{{ $card->public_id }}</code></td>
        <td data-label="QR Status">
          <span class="badge {{ $card->qr_destination ? 'b-ok' : 'b-off' }}">
            {{ $card->qr_destination ? 'Active' : 'Unconfigured' }}
          </span>
        </td>
        {{-- Badge text stays on ONE line: the tests assert the whole
             "label &middot; state" string, and Blade would otherwise wrap
             newlines and indentation into the middle of it. --}}
        <td data-label="NFC Status">
          <span class="badge {{ $nfcOn ? 'b-ok' : 'b-off' }}">{{ $mode->label() }} &middot; {{ $nfcOn ? 'Active' : 'Unconfigured' }}</span>
        </td>
        {{-- The count only means something in Links mode; showing 0 next to a
             live contact card is what made the old dashboard misleading. --}}
        <td data-label="NFC Links" class="mono muted">{{ $isLinks ? $n : '—' }}</td>
        <td data-label="Created" class="muted small">{{ $card->created_at->format('Y-m-d') }}</td>
        <td><a class="btn btn-sm" href="{{ route('admin.cards.show', $card->public_id) }}">View</a></td>
      </tr>
    @empty
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardStatusTest`
Expected: PASS. The badge tests were later folded into the existing `modeStates` provider so all four modes are covered in both states — the three-Active-plus-one-Unconfigured set drafted above left Links unpinned and could not catch a state stuck on Active for phone or email. The link-count assertion reads the cell's text rather than matching a literal attribute order.

Run: `php artisan test`
Expected: PASS. `AdminTest.php` makes no assertion on the dashboard's NFC status text, so no edits there are expected.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/DashboardController.php resources/views/admin/dashboard.blade.php tests/Feature/DashboardStatusTest.php
git commit -m "fix: make the dashboard NFC status mode-aware

Status came from active link count alone, so every card serving a contact
card, phone number or email address read as Unconfigured. Uses the
nfcModeIsConfigured helper that had been sitting unused."
```

---

### Task 9: Dashboard stat counters are mode-aware

The five counters at the top of the dashboard have the same defect as the row status: `nfcConfigured`, `fullyConfigured` and `fullyUnconfigured` all count `whereHas('nfcLinks')`, so a fleet of configured contact cards reports as fully unconfigured.

**Files:**
- Modify: `app/Http/Controllers/Admin/DashboardController.php:59-77`
- Test: `tests/Feature/DashboardStatusTest.php`

**Interfaces:**
- Consumes: `Card::scopeWhereNfcModeConfigured()` from Task 3; the test helpers `cardInMode()` and `card()` from `DashboardStatusTest` as written in Tasks 1 and 3.
- Produces: `Card::scopeWhereQrConfigured(Builder $query, bool $configured = true): Builder`, called as `->whereQrConfigured()` and `->whereQrConfigured(false)`. Also `DashboardController::stats(): array` — unchanged signature and unchanged keys (`total`, `qrConfigured`, `nfcConfigured`, `fullyConfigured`, `fullyUnconfigured`), so the view needs no edit.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardStatusTest.php`:

```php
    public function test_stats_count_every_mode_as_configured(): void
    {
        // One configured card per mode, QR set on all of them.
        foreach (['links', 'contact', 'phone', 'email'] as $mode) {
            $this->cardInMode($mode, true);
        }

        // Two unconfigured: one in contact mode, one left at the links default.
        $this->cardInMode('contact', false);
        $this->card();

        $stats = app(\App\Http\Controllers\Admin\DashboardController::class)->stats();

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

        $stats = app(\App\Http\Controllers\Admin\DashboardController::class)->stats();

        $this->assertSame(1, $stats['qrConfigured']);
        $this->assertSame(0, $stats['nfcConfigured']);
        $this->assertSame(0, $stats['fullyConfigured']);
        $this->assertSame(0, $stats['fullyUnconfigured']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_stats_count_every_mode_as_configured`
Expected: FAIL — `nfcConfigured` is 1 (only the links card) instead of 4, and `fullyUnconfigured` is 5 instead of 2.

- [ ] **Step 3: Write minimal implementation**

Replace `stats()` in `app/Http/Controllers/Admin/DashboardController.php`:

```php
    /** @return array<string,int> */
    public function stats(): array
    {
        $total = Card::count();

        $qrConfigured = Card::query()->whereQrConfigured()->count();

        $nfcConfigured = Card::query()->whereNfcModeConfigured()->count();

        $fullyConfigured = Card::query()
            ->whereQrConfigured()
            ->whereNfcModeConfigured()
            ->count();

        $fullyUnconfigured = Card::query()
            ->whereQrConfigured(false)
            ->whereNfcModeConfigured(false)
            ->count();

        return compact('total', 'qrConfigured', 'nfcConfigured', 'fullyConfigured', 'fullyUnconfigured');
    }
```

That needs one more scope. Add it to `app/Models/Card.php`, next to `scopeWhereNfcModeConfigured()`:

```php
    /**
     * SQL twin of qrIsConfigured(). Mirrors it exactly, including treating an
     * empty string as unconfigured — the previous stats() used a bare
     * whereNotNull and so counted a blank destination as active.
     */
    public function scopeWhereQrConfigured(Builder $query, bool $configured = true): Builder
    {
        return $configured
            ? $query->whereNotNull('qr_destination')->where('qr_destination', '!=', '')
            : $query->where(fn (Builder $q) => $q->whereNull('qr_destination')->orWhere('qr_destination', ''));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardStatusTest`
Expected: PASS. The implemented scope uses the symmetric `coalesce(qr_destination, '') != ''` / `= ''` pair rather than the `whereNotNull` form drafted above — same behaviour, and the same idiom Task 3 settled on for the nullable phone and email columns. A partition test over NULL, empty-string and real-URL fixtures proves the two branches are an exact complement.

Run: `php artisan test`
Expected: PASS. `AdminTest.php` asserts no stat numbers, so no edits there are expected.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/DashboardController.php app/Models/Card.php tests/Feature/DashboardStatusTest.php
git commit -m "fix: count dashboard stats per NFC mode

All three NFC counters tested for active links regardless of mode, so a fleet
of working contact cards reported as fully unconfigured. Also stops counting a
blank qr_destination as configured."
```

---

### Task 10: The dashboard's query count does not grow with rows

Task 8 made every row call `nfcModeIsConfigured()`, which touches links and the contact profile. Tasks 1 and 8 were written to keep that free, but nothing yet proves it, and an N+1 on the admin's landing page is the kind of regression that goes unnoticed until the table is large.

**Files:**
- Test: `tests/Feature/DashboardStatusTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1, 8 and 9, plus the test helpers `cardInMode()` and `admin()` from `DashboardStatusTest`.
- Produces: the test helper `dashboardQueryCount(User $admin): int`.

- [ ] **Step 1: Write the test**

Add to `tests/Feature/DashboardStatusTest.php`:

```php
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

    public function test_dashboard_query_count_does_not_grow_with_the_number_of_cards(): void
    {
        $admin = $this->admin();

        foreach (['links', 'contact', 'phone', 'email'] as $mode) {
            $this->cardInMode($mode, true);
        }
        $small = $this->dashboardQueryCount($admin);

        // 20 rows total: still one page, so pagination is not the variable.
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
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test --filter=test_dashboard_query_count_does_not_grow_with_the_number_of_cards`
Expected: PASS.

If it FAILS, the eager load in Task 8 is not covering something. Diagnose with `dump(DB::getQueryLog())` before the assertion — the statement that repeats once per row names the missing relation. Fix the eager load in `DashboardController::index()`; do not weaken the assertion to a range.

- [ ] **Step 3: Run the whole suite**

Run: `php artisan test`
Expected: PASS, all tests.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/DashboardStatusTest.php
git commit -m "test: assert the dashboard has no N+1 across NFC modes

Row status now reads links and the contact profile, so the eager load and the
preloaded link-count aggregate both need a guard against silent regression."
```

---

## Spec Coverage

Every requirement in section 8 of `docs/superpowers/specs/2026-08-24-nfc-profile-mode-design.md`, mapped to a task:

| Spec requirement | Task |
|---|---|
| `resetNfc` deletes links | 4 |
| `resetNfc` deletes the contact profile and its photo | 4 |
| `resetNfc` clears `nfc_phone_number` and the three `nfc_email_*` columns | 4 |
| `resetNfc` clears the branding columns | 4 |
| `resetNfc` resets `nfc_mode` to `Links` | 4 |
| `resetNfc` success message becomes true in every mode | 6 |
| `resetCard` = `resetNfc` plus the QR reset | 5, 6 |
| A reset Contact-mode card no longer serves the previous holder's name | 7 |
| A reset card's `.vcf` no longer returns 200 | 7 |
| Dashboard uses `Card::nfcModeIsConfigured()` | 8 |
| Dashboard eager-loads only the columns the status check needs | 8 |
| Dashboard avoids an N+1 across the 25-row page | 1, 8, 10 |
| The five stat counters count against each card's current mode | 9 |

Out of scope for this increment, by design: `NfcMode::Profile`, the `nfc_profiles` table, and profile deletion inside `CardResetService`. Increment 2 adds the enum case and the table; the increment that introduces the profile model adds its three lines to `resetNfcSide()`, and the comment in that method says so.

## Notes for the implementer

- Both reset methods delegate to a single private helper with one transaction. Do not reintroduce nesting: see Task 5 for why wrapping `resetNfcSide()` puts the photo file at risk of a rollback it cannot undo.
- `Storage::fake('public')` must be called before `fullyPopulatedCard()`, because that helper writes the fake contact photo.
- The `AdminTest.php` updates named in Tasks 6, 8 and 9 are conditional. Read the file first; if it makes no assertion on those strings or numbers, drop it from the `git add`.
- `bootstrap/cache/routes-v7.php` exists in this repo. If a route change ever seems not to take effect, `php artisan route:clear`. No task here changes routes, so it should not come up.

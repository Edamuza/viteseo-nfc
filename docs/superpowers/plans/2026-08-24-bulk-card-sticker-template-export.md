# Bulk Card Sticker Template Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add one authenticated dashboard action that downloads front and card-specific back PNG templates for every card in a single ZIP.

**Architecture:** Extend `CardReviewStickerService` with an iterable bulk ZIP method that renders the fixed front once and creates each dynamic back. Add a dedicated POST action to `ExportController`, route it inside the existing auth group, and expose it beside the QR export on the dashboard.

**Tech Stack:** Laravel 12, PHP 8.5, GD, ZipArchive, PHPUnit.

## Global Constraints

- Export every card ordered by database ID, including unconfigured cards.
- Include exactly two flat 638 × 1011 PNG entries per card.
- Use zero-trimmed names such as `card-50-front.png` and `card-50-back.png`.
- Every back encodes `Card::qrUrl()`, never `qr_destination`.
- Render the fixed front once per bulk request.
- Use `POST /admin/export/sticker-templates` and route name `admin.export.sticker-templates`.
- Return `nfc-card-templates-YYYYMMDD-HHMMSS.zip`.
- Keep the response authenticated, CSRF-protected, private, and no-store.
- Remove temporary archives on success and failure.
- Do not create a commit unless the user explicitly requests one.

---

### Task 1: Bulk archive service

**Files:**
- Modify: `app/Services/CardReviewStickerService.php`
- Modify: `tests/Feature/CardReviewStickerTest.php`

**Interfaces:**
- Produces: `zipForCards(iterable $cards): string`
- Preserves: `zipFor(Card $card): string`

- [ ] **Step 1: Write failing service tests**

Add tests that create configured and unconfigured cards, call:

```php
$bytes = app(CardReviewStickerService::class)->zipForCards([$card50, $card51]);
$entries = $this->zipEntries($bytes);
```

Assert the exact sorted keys:

```php
[
    'card-50-back.png',
    'card-50-front.png',
    'card-51-back.png',
    'card-51-front.png',
]
```

Assert both fronts are byte-identical, every entry is a 638 × 1011 PNG, backs
are different, and changing only `qr_destination` leaves a back unchanged.
Add success and invalid-card-number failure tests comparing
`storage/app/tmp/sticker-templates-*.zip` before and after the call.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test --filter=CardReviewStickerTest
```

Expected: failures because `zipForCards()` is undefined.

- [ ] **Step 3: Implement the bulk archive**

Add:

```php
public function zipForCards(iterable $cards): string
{
    $front = $this->frontPng();

    return $this->archive('sticker-templates-', function (ZipArchive $zip) use ($cards, $front): void {
        foreach ($cards as $card) {
            if (! $card instanceof Card) {
                throw new RuntimeException('Sticker exports require Card models.');
            }

            $base = $this->downloadBasename($card);
            $this->addEntry($zip, $base.'-front.png', $front);
            $this->addEntry($zip, $base.'-back.png', $this->backPng($card));
        }
    });
}
```

Extract the existing temporary-file lifecycle into:

```php
private function archive(string $prefix, callable $populate): string
private function addEntry(ZipArchive $zip, string $name, string $bytes): void
```

Have `zipFor()` use the same helpers without changing its filenames or cleanup
contract.

- [ ] **Step 4: Verify GREEN**

Run:

```bash
php artisan test tests/Feature/CardReviewStickerTest.php
```

Expected: all individual and bulk service tests pass.

---

### Task 2: Dashboard bulk export

**Files:**
- Modify: `app/Http/Controllers/Admin/ExportController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/dashboard.blade.php`
- Modify: `tests/Feature/CardReviewStickerTest.php`

**Interfaces:**
- Consumes: `CardReviewStickerService::zipForCards(iterable $cards): string`
- Produces: `ExportController::stickerTemplates(): Response`
- Produces: `POST /admin/export/sticker-templates`

- [ ] **Step 1: Write failing endpoint and UI tests**

Assert:

```php
$this->post('/admin/export/sticker-templates')->assertRedirect('/admin/login');
```

For an authenticated admin, freeze time to `2026-08-24 15:52:03`, create two
cards including an unconfigured one, POST the endpoint, and assert:

```php
$response->assertOk()->assertHeader('Content-Type', 'application/zip');
$this->assertStringContainsString(
    'attachment; filename="nfc-card-templates-20260824-155203.zip"',
    (string) $response->headers->get('Content-Disposition')
);
```

Assert private/no-store headers and all four PNG entries. Add an empty-database
404 test, a GET 404 test, and dashboard assertions for the exact POST action and
**Download all templates (PNG)** label.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test --filter=CardReviewStickerTest
```

Expected: route/UI tests fail with 404 or missing CTA.

- [ ] **Step 3: Implement endpoint, route, and CTA**

Inject both services:

```php
public function __construct(
    private readonly QrCodeService $qr,
    private readonly CardReviewStickerService $stickers,
) {}
```

Add:

```php
public function stickerTemplates(): Response
{
    $cards = Card::query()->orderBy('id')->get();
    if ($cards->isEmpty()) {
        abort(404, 'No cards are available for template export.');
    }

    return response($this->stickers->zipForCards($cards), 200, [
        'Content-Type' => 'application/zip',
        'Content-Disposition' => 'attachment; filename="nfc-card-templates-'.now()->format('Ymd-His').'.zip"',
        'Cache-Control' => 'no-store, private',
    ]);
}
```

Add the named POST route inside the authenticated export routes and add this
CSRF-protected form beside the existing SVG export:

```blade
<form method="POST" action="{{ route('admin.export.sticker-templates') }}">@csrf
  <button class="btn btn-sm" type="submit">Download all templates (PNG)</button>
</form>
```

- [ ] **Step 4: Run final verification**

Run:

```bash
php artisan view:clear
php artisan test tests/Feature/CardReviewStickerTest.php tests/Unit/QrCodeServiceTest.php
php artisan test
git diff --check
```

Expected: all tests pass, the diff is clean, and no temporary archive remains.

No commit is created unless the user explicitly requests one.

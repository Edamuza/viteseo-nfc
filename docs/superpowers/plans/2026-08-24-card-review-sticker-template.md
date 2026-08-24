# Card Review Sticker Template Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an authenticated admin download that produces a ZIP containing print-ready front and back PNG stickers, with the selected card's permanent QR composited into the supplied back artwork.

**Architecture:** Extend `QrCodeService` with a backward-compatible maximum-size parameter so sticker QR modules remain integer-scaled. A focused `CardReviewStickerService` owns artwork loading, sizing, QR composition, deterministic filenames, ZIP creation, and temporary-file cleanup. `CardController` exposes one GET download beside the existing QR downloads.

**Tech Stack:** Laravel 12, PHP 8.4, GD, BaconQrCode, ZipArchive, PHPUnit.

## Global Constraints

- Output dimensions are exactly 638 × 1011 pixels.
- Output names for `CARD-0050` are `card-50-front.png`, `card-50-back.png`, and `card-50-template.zip`.
- The back QR encodes `Card::qrUrl()`, never `qr_destination`.
- The action remains available on unconfigured cards with a visible setup-page warning.
- The QR uses level M, a four-module quiet zone, and integer pixel scaling.
- The source artwork is fixed; only the back QR varies.
- Generated files are never written to public storage.
- The endpoint is authenticated, no-store, and private.
- No commit is created unless the user explicitly requests one.

---

### Task 1: Size-aware QR rendering

**Files:**
- Modify: `app/Services/QrCodeService.php`
- Create: `tests/Unit/QrCodeServiceTest.php`

**Interfaces:**
- Produces: `pngForCard(Card $card, int $maxSize = self::PNG_SIZE): string`
- Produces: `png(string $payload, int $maxSize = self::PNG_SIZE): string`

- [ ] **Step 1: Write the failing unit tests**

Create tests that call the wished-for optional parameter:

```php
public function test_png_accepts_a_smaller_maximum_without_resampling_modules(): void
{
    $png = app(QrCodeService::class)->png('https://nfc.viteseo.ph/q/ABCDEFGH', 410);
    $info = getimagesizefromstring($png);

    $this->assertNotFalse($info);
    $this->assertLessThanOrEqual(410, $info[0]);
    $this->assertSame($info[0], $info[1]);
    $this->assertGreaterThanOrEqual(360, $info[0]);
}
```

Add a second test proving the default remains at least 1000px, preserving the
existing admin PNG contract.

- [ ] **Step 2: Run the unit test and verify RED**

```bash
php artisan test tests/Unit/QrCodeServiceTest.php
```

Expected: failure because `png()` ignores/rejects the second argument.

- [ ] **Step 3: Add the optional maximum**

Update both methods without changing the default:

```php
public function pngForCard(Card $card, int $maxSize = self::PNG_SIZE): string
{
    return $this->png($card->qrUrl(), $maxSize);
}

public function png(string $payload, int $maxSize = self::PNG_SIZE): string
{
    if ($maxSize < 1) {
        throw new InvalidArgumentException('QR maximum size must be positive.');
    }

    // Existing matrix path, replacing self::PNG_SIZE with $maxSize.
}
```

Keep `floor($maxSize / $total)` and `$total * $scale`; do not resize a rendered
QR after the fact.

- [ ] **Step 4: Verify GREEN**

```bash
php artisan test tests/Unit/QrCodeServiceTest.php tests/Feature/AdminTest.php
```

Expected: both the new 410px contract and the existing high-resolution download
contract pass.

---

### Task 2: Compose front/back artwork and ZIP

**Files:**
- Add binary: `resources/images/card-review-sticker/front.png`
- Add binary: `resources/images/card-review-sticker/back.png`
- Create: `app/Services/CardReviewStickerService.php`
- Create: `tests/Feature/CardReviewStickerTest.php`

**Interfaces:**
- Consumes: `QrCodeService::pngForCard(Card $card, int $maxSize): string`
- Produces: `frontPng(): string`
- Produces: `backPng(Card $card): string`
- Produces: `zipFor(Card $card): string`
- Produces: `downloadBasename(Card $card): string`

- [ ] **Step 1: Add the approved artwork**

Copy the uploaded Canva exports to stable resource paths:

```text
/Users/admin/.cursor/projects/Users-admin-Desktop-JULIUS-projects-nfc-viteseo-ph/assets/Front-9261e5cd-8db2-4a0e-b891-5dbc4fbc1474.png
  -> resources/images/card-review-sticker/front.png

/Users/admin/.cursor/projects/Users-admin-Desktop-JULIUS-projects-nfc-viteseo-ph/assets/Back-baedba41-b534-4dc8-a04e-40508931887c.png
  -> resources/images/card-review-sticker/back.png
```

Verify both decode as PNG before writing service code.

- [ ] **Step 2: Write failing service tests**

The new feature test must assert:

```php
$service = app(CardReviewStickerService::class);

$front = $service->frontPng();
$back  = $service->backPng($card);

$this->assertSame([638, 1011], array_slice(getimagesizefromstring($front), 0, 2));
$this->assertSame([638, 1011], array_slice(getimagesizefromstring($back), 0, 2));
$this->assertSame('card-50', $service->downloadBasename($cardWithNumber0050));
```

Add tests proving:

- two cards share identical front bytes;
- two different public IDs produce different back bytes;
- changing only `qr_destination` leaves back bytes identical;
- the scanner-bracket sample pixels remain grey;
- the cleared sample-QR margin is white;
- every pixel sampled from generated QR modules is black or white;
- the ZIP contains exactly the two required names and valid PNG bytes;
- no new file remains under `storage/app/tmp` after `zipFor()`.

- [ ] **Step 3: Run the service tests and verify RED**

```bash
php artisan test --filter=CardReviewStickerTest
```

Expected: failure because `CardReviewStickerService` does not exist.

- [ ] **Step 4: Implement composition**

Create these constants:

```php
public const WIDTH = 638;
public const HEIGHT = 1011;
private const QR_CLEAR_X = 106;
private const QR_CLEAR_Y = 77;
private const QR_CLEAR_SIZE = 426;
private const QR_MAX_SIZE = 410;
```

Implementation rules:

1. Decode the source with `imagecreatefrompng`; throw `RuntimeException` on
   failure.
2. Resize artwork to 638 × 1011 using GD and preserve alpha.
3. For the back, fill the clear square white.
4. Decode `QrCodeService::pngForCard($card, 410)`.
5. Centre it with:

```php
$x = self::QR_CLEAR_X + intdiv(self::QR_CLEAR_SIZE - imagesx($qr), 2);
$y = self::QR_CLEAR_Y + intdiv(self::QR_CLEAR_SIZE - imagesy($qr), 2);
imagecopy($back, $qr, $x, $y, 0, 0, imagesx($qr), imagesy($qr));
```

6. Encode PNG at compression 9 and destroy every GD resource in `finally`.
7. Parse `CARD-0050` to `card-50`; reject an unexpected card-number format
   rather than allowing it into ZIP names.
8. Create the ZIP in `storage/app/tmp`, add only the two PNG entries, close and
   read it, then unlink it in `finally`.

- [ ] **Step 5: Verify GREEN**

```bash
php artisan test --filter=CardReviewStickerTest
```

Expected: all composition, naming, payload-invariance, ZIP, and cleanup tests
pass.

---

### Task 3: Authenticated admin download

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Admin/CardController.php`
- Modify: `resources/views/admin/card.blade.php`
- Modify: `tests/Feature/CardReviewStickerTest.php`

**Interfaces:**
- Consumes: `CardReviewStickerService::zipFor()` and `downloadBasename()`
- Produces: `GET /admin/cards/{card}/sticker-template.zip`
- Produces: route name `admin.cards.sticker.download`

- [ ] **Step 1: Write failing endpoint and UI tests**

Add tests proving:

```php
$path = '/admin/cards/'.$card->public_id.'/sticker-template.zip';

$this->get($path)->assertRedirect('/admin/login');

$response = $this->actingAs($admin)->get($path)->assertOk();
$response->assertHeader('Content-Type', 'application/zip');
$this->assertStringContainsString(
    'attachment; filename="card-50-template.zip"',
    $response->headers->get('Content-Disposition')
);
```

Also assert no-store/private headers, the admin page's exact action URL and
button label, and the unconfigured warning.

- [ ] **Step 2: Run and verify RED**

```bash
php artisan test --filter=CardReviewStickerTest
```

Expected: 404 for the new route and missing CTA.

- [ ] **Step 3: Wire route, controller, and view**

Route:

```php
Route::get('/sticker-template.zip', [CardController::class, 'downloadStickerTemplate'])
    ->name('sticker.download');
```

Inject `CardReviewStickerService` into `CardController`. Return:

```php
return response($this->stickers->zipFor($card), 200, [
    'Content-Type'        => 'application/zip',
    'Content-Disposition' => 'attachment; filename="'.
        $this->stickers->downloadBasename($card).'-template.zip"',
    'Cache-Control'       => 'no-store, private',
]);
```

Add **Download sticker template** beside the existing SVG/PNG links. When
`$qrOn` is false, keep the existing setup-page message and add that the template
can still be downloaded because the permanent QR URL does not change.

- [ ] **Step 4: Run focused and full verification**

```bash
php artisan view:clear
php artisan test --filter=CardReviewStickerTest
php artisan test
```

Expected: all tests pass with no warnings or skips.

- [ ] **Step 5: Mutation checks**

Prove the tests fail when:

1. the controller uses `qr_destination` instead of `qrUrl()`;
2. `QR_CLEAR_SIZE` is zero, leaving the sample QR;
3. filename normalisation preserves `0050`;
4. the route is moved outside the auth group;
5. the CTA is removed from the admin page.

Restore each mutation, rerun focused tests, and run `git diff --check`.

No commit is created unless the user explicitly requests one.

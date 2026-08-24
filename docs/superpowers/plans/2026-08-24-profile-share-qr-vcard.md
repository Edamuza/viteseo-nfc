# Profile Share, QR Split, and vCard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete Increment 3 with the Share modal, distinct card/profile QR paths, public profile QR download, and profile-derived vCard.

**Architecture:** Keep the printed card QR on the existing level-M `/q/` path. Add a level-H profile PNG path that encodes `/n/` and composites the avatar or initials in its centre. Extend `NfcController` and `VCardBuilder`, then port the approved mockup markup into the public profile view.

**Tech Stack:** Laravel 12, PHP 8.5, GD, BaconQrCode, Blade, PHPUnit.

## Global Constraints

- The avatar badge QR encodes `Card::qrUrl()` (`/q/`) at existing level M.
- The Share modal/download QR encodes `Card::nfcUrl()` (`/n/`) at level H.
- The profile QR centre mark is at most 22% of the PNG width and has a white border.
- Public QR and vCard routes return 404 unless the card is in meaningful Profile mode.
- Share uses CSS `:target`; only clipboard/toast uses JavaScript.
- The overflow menu has exactly Edit your profile and Copy link.
- The vCard is version 3.0 and reuses the existing escaping helpers.
- No commit is created unless explicitly requested.

---

### Task 1: Level-H profile QR and public download

**Files:**
- Modify: `app/Services/QrCodeService.php`
- Modify: `app/Http/Controllers/Public/NfcController.php`
- Modify: `routes/web.php`
- Modify: `tests/Unit/QrCodeServiceTest.php`
- Modify: `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Produces: `QrCodeService::pngForProfile(Card $card, NfcProfile $profile, int $maxSize = self::PNG_SIZE): string`
- Produces: `GET /n/{card}/qr.png`, route `nfc.profile.qr`

- [ ] **Step 1: Write failing QR tests**

Assert profile PNG validity, square print resolution, output changes with
`public_id`, and output differs from a level-M card PNG. Store a 64×64 avatar on
the public disk and assert the centre pixel matches the avatar while the centre
mark width stays at or below 22% plus its border.

Feature tests must assert unauthorised modes return 404 and a meaningful Profile
card returns:

```php
$response->assertOk()
    ->assertHeader('Content-Type', 'image/png');
```

Also assert `no-store`, `private`, `noindex`, PNG magic bytes, and byte equality
with `QrCodeService::pngForProfile()`.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test tests/Unit/QrCodeServiceTest.php --filter=profile
php artisan test tests/Feature/NfcProfileTest.php --filter=profile_qr
```

Expected: undefined profile QR method and 404 route.

- [ ] **Step 3: Implement profile QR**

Refactor PNG matrix rendering into a private method accepting an
`ErrorCorrectionLevel`; preserve existing public `png()` behavior with level M.
Add `pngForProfile()` using `ErrorCorrectionLevel::H()`, `Card::nfcUrl()`, and a
centre tile. Read avatars from `Storage::disk('public')`; crop/resample them into
a square. If no readable avatar exists, render the profile initials on a
`brandColor()` tile. Never allow the tile width beyond 22% of the QR width.

Add `NfcController::profileQr()` with Profile/meaningful guards and these
headers:

```php
[
    'Content-Type' => 'image/png',
    'Content-Disposition' => 'attachment; filename="profile-'.$card->public_id.'-qr.png"',
    'Cache-Control' => 'no-store, private, no-cache, must-revalidate, max-age=0',
    'X-Content-Type-Options' => 'nosniff',
    'X-Robots-Tag' => 'noindex, nofollow',
]
```

Route it before `/n/{card}`-adjacent fallback concerns:

```php
Route::get('/n/{card}/qr.png', [NfcController::class, 'profileQr'])
    ->name('nfc.profile.qr');
```

- [ ] **Step 4: Verify GREEN**

Run both focused commands from Step 2. Expected: PASS.

---

### Task 2: Profile vCard

**Files:**
- Modify: `app/Services/VCardBuilder.php`
- Modify: `app/Http/Controllers/Public/NfcController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/VCardTest.php`

**Interfaces:**
- Produces: `VCardBuilder::buildProfile(NfcProfile $profile, iterable $links): string`
- Produces: `GET /n/{card}/profile.vcf`, route `nfc.profile.vcard`

- [ ] **Step 1: Write failing vCard tests**

Cover:

```text
BEGIN:VCARD
VERSION:3.0
FN:Uriel Papa
TITLE:Chief Executive Officer
ORG:Vite SEO Digital Advertising OPC
URL:https://...
NOTE:Say my name
END:VCARD
```

Assert one URL per active link, inactive links omitted, a headline without a
comma emits TITLE but no ORG, and CR/LF/comma/semicolon are escaped rather than
creating properties. Assert non-Profile/non-meaningful cards return 404 and the
successful response is no-store/noindex with a safe filename.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test tests/Feature/VCardTest.php --filter=profile
```

Expected: undefined builder method and missing route.

- [ ] **Step 3: Implement profile vCard**

Add `buildProfile()` sharing `esc()` and `structured()`:

```php
$lines[] = 'N:'.$this->structured(['', $profile->display_name, '', '', '']);
$lines[] = 'FN:'.$this->esc($profile->display_name);
```

Split `headline` once on comma, trim both sides, emit non-empty TITLE/ORG, emit
one escaped URL per supplied link, and NOTE from tagline. Add REV and terminal
CRLF exactly like `build()`.

Add guarded `NfcController::profileVcard()` and:

```php
Route::get('/n/{card}/profile.vcf', [NfcController::class, 'profileVcard'])
    ->name('nfc.profile.vcard');
```

- [ ] **Step 4: Verify GREEN**

Run the focused vCard test command. Expected: PASS.

---

### Task 3: Share modal, QR badge, overflow menu, and toast

**Files:**
- Modify: `app/Http/Controllers/Public/NfcController.php`
- Modify: `resources/views/public/nfc-profile.blade.php`
- Modify: `tests/Feature/NfcProfileTest.php`

**Interfaces:**
- Consumes: `QrCodeService::svgForCard()` for `/q/`
- Consumes: routes `nfc.profile.qr`, `nfc.profile.vcard`

- [ ] **Step 1: Write failing markup/security tests**

Assert:

- a topbar overflow menu with exactly Edit your profile and Copy link;
- Edit points to `/n/{public_id}/edit`;
- Copy carries the exact `nfcUrl()` in `data-copy`;
- the avatar badge contains the exact generated `svgForCard()` output;
- Share is `<a class="sharebtn" href="#share">`;
- `#share` is a centred dialog with QR download, encoded `mailto:`, profile
  vCard route, visible profile URL, and modal Copy link;
- the modal profile QR uses the public PNG route rather than the card SVG;
- the toast has `role="status" aria-live="polite"`;
- the only script binds `[data-copy]` and never injects profile values into
  executable JavaScript.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test tests/Feature/NfcProfileTest.php --filter='share|badge|overflow|copy'
```

Expected: missing markup assertions fail.

- [ ] **Step 3: Port the approved UI**

Inject `QrCodeService` into `NfcController` and pass `cardQrSvg` plus an RFC3986
mailto URL to the view.

Port from the approved mockup:

- `.topbar`, `details.menu`, `.menu-panel`;
- `details.qr`, `.qr-pop`;
- `.sharebtn`;
- `.qr-stage`, `.qr-card`, `.modal-row`, `.linkbar`, `.copybtn`;
- `.toast` and reduced-motion rule.

Use `{!! $cardQrSvg !!}` only for service-generated SVG. Use Blade escaping for
all model content. Use `<img>` pointing to `nfc.profile.qr` in the Share stage.
The Edit link may point to its future Increment-6 URL as a literal generated
path before the named edit route exists.

Add the single progressive-enhancement script from the mockup, with a clipboard
feature check and a textarea fallback so Copy still works outside secure browser
contexts.

- [ ] **Step 4: Run Increment 3 verification**

Run:

```bash
php artisan view:clear
php artisan test tests/Unit/QrCodeServiceTest.php tests/Feature/VCardTest.php tests/Feature/NfcProfileTest.php
php artisan test
git diff --check
```

Expected: all tests pass and no diff errors.

No commit is created unless explicitly requested.

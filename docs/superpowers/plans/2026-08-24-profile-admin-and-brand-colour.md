# Profile Admin and Brand Colour Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete Increment 5 with the full admin Profile editor, avatar lifecycle, derived brand colour, and mode-aware card status.

**Architecture:** Keep profile writes in a focused `CardProfileController`. Extend `ImageIngest` with an inspection callback so `DominantColor` observes the same decoded/resized GD image before re-encoding, avoiding a second decode. Persist only server-derived avatar paths and colours.

**Tech Stack:** Laravel 12, PHP 8.5, GD, Eloquent, Blade, PHPUnit.

## Global Constraints

- `brand_color` is derived only; it is never request-fillable.
- Avatar upload uses the existing JPEG/PNG/WebP, 2 MB, 4000×4000 validation and is re-encoded to JPEG at max edge 512.
- Derived colours preserve hue and normalize HSL lightness into 18–24%.
- No avatar, grayscale, transparent, or failed extraction uses `#0f1713`.
- Deleting an avatar clears both `avatar_path` and `brand_color`.
- Welcome body is plain text, max 600 characters; CTA accepts only public HTTP(S).
- Every single-line text field is stripped of control characters.
- Existing reset/dashboard Profile behavior remains intact.
- No commit is created unless explicitly requested.

---

### Task 1: Dominant colour extraction

**Files:**
- Create: `app/Services/DominantColor.php`
- Create: `tests/Unit/DominantColorTest.php`

**Interfaces:**
- Produces: `DominantColor::extract(\GdImage $source): string`

- [ ] Write failing tests using generated GD fixtures for dominant red/green/blue
hues, grayscale fallback, fully transparent fallback, pale and neon
normalization, and `/^#[0-9a-f]{6}$/`.
- [ ] Run `php artisan test tests/Unit/DominantColorTest.php`; expect missing class.
- [ ] Implement 32×32 sampling, discard alpha/near-white/near-black/low
saturation pixels, group survivors into 4-bit RGB buckets, average the winning
bucket, convert RGB↔HSL, clamp saturation to a usable range and lightness to
0.21.
- [ ] Run the focused tests; expect PASS.

---

### Task 2: Inspect the same hardened image decode

**Files:**
- Modify: `app/Services/ImageIngest.php`
- Modify: `tests/Feature/ImageIngestTest.php`

**Interfaces:**
- Produces:

```php
public function storeWithInspection(
    UploadedFile $file,
    string $directory,
    int $maxEdge,
    callable $inspector
): array // ['path' => string, 'inspection' => mixed]
```

- [ ] Write a failing test proving the inspector receives the resized GD image,
its return value is returned, and the stored file is still a clean JPEG.
- [ ] Run the focused test and confirm the method is missing.
- [ ] Refactor decode/resize/re-encode into `storeWithInspection()`; keep
`store()` as a wrapper so contact uploads are behaviorally unchanged.
- [ ] Replace deprecated `imagedestroy()` calls with `unset()`.
- [ ] Run all ImageIngest tests; expect PASS.

---

### Task 3: Focused admin profile controller and avatar lifecycle

**Files:**
- Create: `app/Http/Controllers/Admin/CardProfileController.php`
- Create: `tests/Feature/AdminProfileManagementTest.php`
- Modify: `app/Http/Controllers/Admin/CardController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Produces: `update(Request $request, Card $card): RedirectResponse`
- Produces: `destroy(Card $card): RedirectResponse`
- Produces: `destroyAvatar(Card $card): RedirectResponse`

- [ ] Write failing feature tests for every text/welcome field, limits, CTA URL
scheme rejection, control stripping, ignored `brand_color`/`avatar_path` input,
avatar upload/replace cleanup, derived colour, avatar-only delete, whole-profile
delete, and authentication.
- [ ] Run the new test file; expect missing route/controller behavior.
- [ ] Implement validation:

```php
'display_name' => ['nullable', 'string', 'max:120'],
'headline' => ['nullable', 'string', 'max:200'],
'username' => ['nullable', 'string', 'max:60'],
'location' => ['nullable', 'string', 'max:100'],
'tagline' => ['nullable', 'string', 'max:200'],
'welcome_eyebrow' => ['nullable', 'string', 'max:60'],
'welcome_heading' => ['nullable', 'string', 'max:200'],
'welcome_body' => ['nullable', 'string', 'max:600'],
'welcome_cta_label' => ['nullable', 'string', 'max:60'],
'welcome_cta_url' => ['nullable', 'string', 'max:500', new SafeExternalUrl()],
'avatar' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
```

- [ ] Use `storeWithInspection(..., fn ($image) => $colors->extract($image))`,
save the new path/colour before deleting the old path, and clean up a newly
stored file if persistence fails.
- [ ] Move existing profile delete routing to the new controller and add
`DELETE /profile/avatar`.
- [ ] Remove moved profile methods from `CardController`.
- [ ] Run new and existing Profile admin tests; expect PASS.

---

### Task 4: Full Profile panel and mode-aware card header

**Files:**
- Modify: `resources/views/admin/card.blade.php`
- Modify: `tests/Feature/AdminProfilePanelTest.php`
- Modify: `tests/Feature/AdminProfileManagementTest.php`

**Interfaces:**
- Consumes: existing route names plus `admin.cards.profile.avatar.delete`

- [ ] Replace the deferred-fields test with failing assertions for all identity,
welcome, avatar, delete-avatar, and Preview fields.
- [ ] Add a failing test that a configured Profile card's header does not say
NFC Unconfigured.
- [ ] Replace the one-field form with a multipart form containing identity,
avatar, welcome, and CTA sections. Keep the shared Accounts link editor below.
- [ ] Show Preview when meaningful; show existing avatar and an avatar-only
delete action when present.
- [ ] Calculate the header badge from `Card::nfcModeIsConfigured()` rather than
active-link count alone.
- [ ] Run:

```bash
php artisan test tests/Unit/DominantColorTest.php tests/Feature/ImageIngestTest.php
php artisan test tests/Feature/AdminProfilePanelTest.php tests/Feature/AdminProfileManagementTest.php
php artisan test
git diff --check
```

Expected: all tests pass and no diff errors.

No commit is created unless explicitly requested.

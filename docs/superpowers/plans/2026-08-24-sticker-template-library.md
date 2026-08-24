# Sticker Template Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admin keep named front/back sticker pairs, auto-detect a transparent QR hole on new backs, and download a card-number range against a chosen template.

**Architecture:** `sticker_templates` stores name, PNG paths, detected box, and one active flag. A detector finds the largest fully-transparent rectangle; a PNG ingest scales to 638×1011 with alpha. `CardReviewStickerService` composites using a template row instead of the resource path. Admin CRUD plus a ranged bulk export sit on existing auth routes.

**Tech Stack:** Laravel 12, Eloquent, GD, ZipArchive, PHPUnit, Blade.

## Global Constraints

- Print canvas is exactly 638 × 1011 px.
- ZIP names stay `card-{n}-front.png` / `card-{n}-back.png` and `nfc-card-templates-YYYYMMDD-HHMMSS.zip`.
- PNG with real alpha only for uploads; JPEG/WebP/SVG rejected.
- Auto-detect only; no manual coordinates. Minimum inscribed square is 200 px.
- Detect before the rounded-corner mask. GD fully-transparent pixels are `alpha === 127`.
- Seeded `Default` uses box 106/77/426 without detection (bundled back has no hole).
- Exactly one active template, enforced in a transaction.
- Cannot delete the active row or the last remaining row.
- Bulk span `to - from + 1` ≤ 200. Zero matches = error, not an empty ZIP.
- Inactive templates may be chosen for bulk export.
- Card-review download always uses the active template.
- Request data cannot set paths or `qr_*`.
- Composed ZIPs stay in `storage/app/tmp`. Template files live on the public disk.
- No commit unless the user explicitly requests one.

## File map

- Create: `database/migrations/2026_08_24_000004_create_sticker_templates_table.php`
- Create: `app/Models/StickerTemplate.php`
- Create: `app/Services/QrHoleDetector.php`
- Create: `app/Services/StickerArtworkIngest.php`
- Create: `app/Http/Controllers/Admin/StickerTemplateController.php`
- Create: `resources/views/admin/sticker-templates.blade.php`
- Create: `tests/Unit/QrHoleDetectorTest.php`
- Create: `tests/Feature/StickerTemplateLibraryTest.php`
- Modify: `app/Services/CardReviewStickerService.php`
- Modify: `app/Http/Controllers/Admin/ExportController.php`
- Modify: `app/Http/Controllers/Admin/CardController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `resources/views/admin/dashboard.blade.php`
- Modify: `resources/views/admin/card.blade.php`
- Modify: `tests/Feature/CardReviewStickerTest.php`
- Modify: `tests/Feature/AdminTest.php`

---

### Task 1: Model, migration, seed

**Files:**
- Create: `database/migrations/2026_08_24_000004_create_sticker_templates_table.php`
- Create: `app/Models/StickerTemplate.php`
- Modify: `tests/Feature/StickerTemplateLibraryTest.php`

**Interfaces:**
- Produces: `StickerTemplate` with `name` fillable; `qr_x`, `qr_y`, `qr_size` integer casts; `is_active` boolean
- Produces: `StickerTemplate::active(): ?self`
- Produces: seed row `Default`, `is_active = true`, box `106, 77, 426`

- [ ] Write failing tests for seed, fillable lock, and `active()`.
- [ ] Create table + seed that copies bundled PNGs to `sticker-templates/{hex}.png` on the public disk.
- [ ] Implement the model. Run focused tests green.

---

### Task 2: Hole detector

**Files:**
- Create: `app/Services/QrHoleDetector.php`
- Create: `tests/Unit/QrHoleDetectorTest.php`

**Interfaces:**
- Produces: `QrHoleDetector::inscribedSquare(GdImage $image): ?array{x:int,y:int,size:int}`
- Fully transparent = GD `alpha === 127`. Return null if no rectangle has `min(w,h) >= 200`.

- [ ] Write failing tests: 250×250 hole, 40×400 strip, no hole, two holes (largest wins), inscribed square centered in a 300×400 hole.
- [ ] Implement largest-rectangle-of-1s, then inscribe the centered square.
- [ ] Run unit tests green.

---

### Task 3: PNG ingest and composer

**Files:**
- Create: `app/Services/StickerArtworkIngest.php`
- Modify: `app/Services/CardReviewStickerService.php`
- Modify: `tests/Feature/CardReviewStickerTest.php`
- Modify: `tests/Feature/StickerTemplateLibraryTest.php`

**Interfaces:**
- Produces: `StickerArtworkIngest::store(UploadedFile $file): string` — exact 638×1011 PNG with alpha
- Produces: `CardReviewStickerService::{frontPng,backPng,zipFor,zipForCards}(..., ?StickerTemplate $template = null)`
- Null template resolves `StickerTemplate::active()`.

- [ ] Keep existing sticker tests green against seeded Default.
- [ ] Add a test that a custom template’s hole receives the QR and leaves artwork outside the box.
- [ ] Composer paints white at `qr_x/y/size`, centers QR at `qr_size - 16`, then applies the existing 14 px mask.

---

### Task 4: Admin library

**Files:**
- Create: `app/Http/Controllers/Admin/StickerTemplateController.php`
- Create: `resources/views/admin/sticker-templates.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `tests/Feature/AdminTest.php`
- Modify: `tests/Feature/StickerTemplateLibraryTest.php`

**Interfaces:**
- `GET/POST /admin/sticker-templates`
- `PATCH /admin/sticker-templates/{template}`
- `POST /admin/sticker-templates/{template}/activate`
- `DELETE /admin/sticker-templates/{template}`

- [ ] Tests: guest blocked; create with hole; reject no-hole / tiny hole; failed replace keeps old files; replace front does not change box; activate leaves one active; cannot delete active or last; mass-assignment cannot set paths/`qr_*`; nav link present.
- [ ] Implement CRUD with transactional activate and post-commit file deletes.

---

### Task 5: Downloads and dashboard

**Files:**
- Modify: `app/Http/Controllers/Admin/ExportController.php`
- Modify: `app/Http/Controllers/Admin/CardController.php`
- Modify: `resources/views/admin/dashboard.blade.php`
- Modify: `resources/views/admin/card.blade.php`
- Modify: `tests/Feature/CardReviewStickerTest.php`
- Modify: `tests/Feature/StickerTemplateLibraryTest.php`

**Interfaces:**
- Bulk POST requires `from`, `to`, `template_id`. Span ≤ 200. Select cards by numeric `CARD-0*(\d+)`.
- Card GET download uses active template. Page shows its name and a library link.

- [ ] Update existing bulk tests to send range + template_id.
- [ ] Add tests: 25–50 uses template B’s box; span 201 fails; empty match fails; card download still uses active when B exists.
- [ ] Implement and run `php artisan test tests/Feature/CardReviewStickerTest.php tests/Feature/StickerTemplateLibraryTest.php tests/Unit/QrHoleDetectorTest.php tests/Feature/AdminTest.php` plus `git diff --check`.

Expected: all pass, no diff errors.

No commit is created unless explicitly requested.

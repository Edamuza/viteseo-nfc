# Sticker Template Library — Design

Date: 2026-08-24
Status: Approved for planning

## Goal

Let ViteSEO keep a library of print-ready sticker designs (front + back pairs),
pick one as the default, and download card stickers against a chosen design.
The back PNG carries a fully transparent hole; the server detects that hole on
upload and composites each card's permanent QR (`/q/{card}`) into it.

Bulk download is no longer "every card, one hardcoded pair". It is a range of
card numbers plus an explicit template. One-click download on a card review page
uses the active template.

## Non-goals

- Per-card saved template assignment. A card does not remember a design.
- Manual X/Y/size entry, sliders, or a visual box editor.
- JPEG, WebP, or SVG templates. PNG with a real alpha channel only.
- Auto-detecting anti-aliased / semi-transparent fades as a hole.
- Mixing front from one template with back from another.
- Client (cardholder) access to templates or downloads.
- Changing the print size (stays 638×1011 px, 54×85.6 mm at 300 DPI).
- Changing ZIP entry names (`card-50-front.png`, `card-50-back.png`).

## Decisions already locked

- One *active* template is the default for single-card download.
- Bulk download chooses **range and template** together. An inactive template
  may be selected for a batch without making it active.
- A template is a **front + back pair**.
- Hole detection is **automatic and reject-only**. No coordinate fallback.
- The current shipped `resources/images/card-review-sticker/{front,back}.png`
  is seeded as the first row, named `Default`, and starts active.

---

## 1. Data

### `sticker_templates`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `name` | string(80), unique | Admin label, e.g. `Default`, `Green batch` |
| `front_path` | string(255) | Server-generated path on the public disk |
| `back_path` | string(255) | Server-generated path on the public disk |
| `qr_x` | unsigned integer | Top-left of the inscribed QR square, in canvas pixels |
| `qr_y` | unsigned integer | |
| `qr_size` | unsigned integer | Side length of the inscribed square |
| `is_active` | boolean, default false | Exactly one row is true at a time |
| `timestamps` | | |

There is no database unique index on `is_active`. "Exactly one active" is
enforced in a transaction: activating A sets A true and every other row false.
The seed migration inserts `Default` with `is_active = true`.

`App\Models\StickerTemplate` fillable is only `name`. Paths, box, and active
flag are set by the controller / seeder. Deleting a card does not touch this
table; templates are global.

### Files

Stored on the public disk under `sticker-templates/{random32}.png`, the same
hardening pattern as profile avatars: GD decode, scale to 638×1011, re-encode
as PNG **with alpha preserved**. Request data cannot set `front_path` or
`back_path`.

Admin thumbs are served from `/storage/sticker-templates/...`.

### Seed

The current bundled back is a designed plate with a **hardcoded** QR box
(106, 77, 426), not a cut-out hole. Day-one seed must not run auto-detect on
that file.

On migrate:

1. Copy `resources/images/card-review-sticker/{front,back}.png` onto the public
   disk as the `Default` files.
2. Insert `Default` as active with `qr_x = 106`, `qr_y = 77`, `qr_size = 426`.
3. After seed, every download reads library rows. The resource files remain
   only as the seed source.

New and replaced backs still require a real alpha-0 hole. Replacing `Default`'s
back switches it from the seeded constants to a detected box. Composed ZIP
bytes still land only in `storage/app/tmp`, never on the public disk.

---

## 2. Hole detection

Detection runs on the **scaled 638×1011 back canvas, before the rounded-corner
mask**. The mask punches transparent corners for print; those pixels must not
be treated as a QR hole.

Algorithm:

1. Build a binary mask of pixels whose alpha is **0** (fully transparent).
   Partial alpha is opaque for this purpose.
2. Find the largest-area axis-aligned rectangle that is entirely in that mask
   (largest rectangle of 1s).
3. If none, or the rectangle's smaller side is **below 200 px**, reject.
4. Inscribe a square of side `min(width, height)` centered in that rectangle.
5. Persist `qr_x`, `qr_y`, `qr_size` from that square.

If two holes exist, the larger rectangle wins. A thin transparent strip fails
the 200 px rule. Anti-aliased holes fail unless they contain a ≥200 px core of
true alpha-0 pixels — authors must cut a hard-edged hole.

On **create**, both files are required and the back must pass detection or no
row is written. On **replace**, new files are stored first; if the new back
fails detection, the new files are deleted and the previous paths/box stay.
Replacing only the front leaves the existing box unchanged. Replacing the back
always re-detects.

---

## 3. Composition

`CardReviewStickerService` takes an explicit `StickerTemplate` (or resolves the
active one). For each back:

1. Load the stored back PNG onto the 638×1011 canvas.
2. Fill `qr_x, qr_y, qr_size` with opaque white.
3. Render the card QR at a max size of `qr_size - 16` (8 px padding each side,
   matching today's 426 clear / 410 QR relationship) and center it in the box.
4. Apply the existing 14 px rounded-corner mask.
5. Encode PNG.

Front is the stored front, scaled/masked the same way, no QR. ZIP layout and
basenames stay `card-{n}-front.png` / `card-{n}-back.png` via the existing
`downloadBasename()`. Bulk ZIP name stays
`nfc-card-templates-YYYYMMDD-HHMMSS.zip`.

---

## 4. Admin library

New authenticated page: **Sticker templates**, nav link next to Site Pages.

Route prefix `/admin/sticker-templates`:

| Method | Path | Name | Action |
|---|---|---|---|
| GET | `/admin/sticker-templates` | `admin.sticker-templates.index` | Library |
| POST | `/admin/sticker-templates` | `admin.sticker-templates.store` | Create |
| PATCH | `/admin/sticker-templates/{template}` | `admin.sticker-templates.update` | Rename and/or replace files |
| POST | `/admin/sticker-templates/{template}/activate` | `admin.sticker-templates.activate` | Make active |
| DELETE | `/admin/sticker-templates/{template}` | `admin.sticker-templates.destroy` | Delete |

Each row on the page shows name, front thumb, back thumb with a box overlay
drawn from `qr_x/y/size`, and an Active badge. Actions: Set active, replace
front, replace back, delete.

Create requires `name`, `front`, `back`. Name is unique, max 80, single-line
stripped of control characters.

Delete is refused when the row is active, or when it is the only template.
Activating another row is required before deleting the current default.
Deleting a row removes its two files after the database commit.

Unauthenticated access redirects to login. Same `adminGetRoutes` coverage as
Site Pages.

---

## 5. Downloads

### Card review (one click)

`GET /admin/cards/{card}/sticker-template.zip` is unchanged in URL and ZIP
shape. It always uses `StickerTemplate` where `is_active = true`. If none
exists (should be unreachable after seed), 404 with a clear message.

The card page shows the active template name under the button and links to
the library so the default can be switched before download. No per-download
dropdown on this page.

### Dashboard bulk

`POST /admin/export/sticker-templates` gains a form:

- `from` — integer, min 1
- `to` — integer, `gte:from`
- `template_id` — exists in `sticker_templates`

`to - from + 1` must be **≤ 200**. This is a span cap, not a "found cards"
cap: 25–50 is 26 numbers and is allowed; 1–201 is rejected even if many
numbers were never generated.

Cards are selected by the numeric part of `card_number` (`CARD-0025` → 25),
inclusive, ordered by that number. Missing numbers are skipped. If **zero**
cards match, redirect back with an error — never an empty ZIP.

The chosen `template_id` may be inactive. The ZIP contains only the matched
cards, each composited with that template.

Default dropdown value is the active template. Filename unchanged.

---

## 6. Errors and limits

- PNG only, `image/png`, max 2 MB, max source edge 4000 px.
- Unreadable or non-PNG uploads fail with a field error.
- No hole / hole &lt; 200 px: `back` validation error, no row change.
- Failed replace: previous files and box survive; newly stored rejects are
  deleted.
- Last-template delete and active-template delete: 422 / redirect error,
  row remains.
- Unauthenticated: login redirect.
- Bulk range inverted, non-integer, or span &gt; 200: validation error.
- Bulk range that matches nothing: status/error, no ZIP.
- Unknown `template_id`: validation error.

---

## 7. Tests

- Migration seed: one `Default` row, active, box is exactly 106/77/426.
  Single-card ZIP still encodes `/q/{card}` in that box. Replacing that back
  with a holed PNG updates the stored box.
- Create with a 250×250 alpha-0 square stores that inscribed box and the
  download places the QR inside it (pixels outside the box stay as artwork).
- Create without a hole, or with a 40×400 strip, is rejected and inserts
  nothing.
- Replace-back that fails detection leaves the previous path and box; the
  rejected file is not left on disk.
- Replace-front does not change `qr_x/y/size`.
- Activate B leaves exactly one active row.
- Delete active or last template is refused; delete of a non-active extra
  template removes the row and files.
- Request cannot set `front_path`, `back_path`, or `qr_*`.
- Bulk `from=25, to=50, template_id=B` includes only those card numbers and
  uses B's box, not the active template's.
- Bulk span 201 and bulk empty match fail closed.
- Guest cannot open the library or trigger either download.
- Card-review download uses the active template even when another row exists.

---

## Delivery

One increment. It replaces the hardcoded artwork path inside
`CardReviewStickerService` and extends the existing bulk export form. No
public NFC behaviour changes.

## Out of scope follow-ups

- Per-card template assignment.
- Hole editor if Canva-flatten complaints appear in real use.
- Preview PDF contact sheets.

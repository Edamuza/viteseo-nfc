# NFC Profile Mode — Design

Date: 2026-08-24
Status: Approved for planning

## Goal

Add a fifth NFC mode, `Profile`, that renders a rich Gravatar-style public profile
page: a brand-coloured cover band, a circular avatar with a QR badge, name,
headline, `username · location`, a row of social icons, a tagline, a Share
control, and an accounts panel listing each link with its platform icon and
display URL.

It also carries a Share modal with a scannable profile QR and a vCard download
(sections 11–12), an admin-authored welcome panel inside Advanced details
(section 10), and a PIN-gated form letting the cardholder edit their own details
(section 13).

The existing `Links` mode stays exactly as it is — minimal, unbranded, and
redirect-first. Profile is a separate mode so neither behaviour has to compromise
for the other.

## Non-goals

These are deliberately out of scope for v1:

- **Verification badges.** Gravatar's blue checks mean cryptographically proven
  account ownership. Nothing in this system proves that. Rendering a checkmark
  next to an admin-typed URL would assert a guarantee that does not exist, so the
  panel is titled "Accounts" and carries no badges.
- Avatar cropping or repositioning UI.
- Per-link click analytics.
- A separate uploaded cover image. The cover is a translucent wash of the brand
  colour (section 15), not its own upload.
- Rich text or HTML in any admin-authored copy. Plain text with paragraph breaks
  only (section 16).

## Architecture

### 1. Mode enum

`App\Enums\NfcMode` gains a fifth case:

```php
case Profile = 'profile';
```

with a label of "Profile Page" and the description "Shows a rich profile page
with avatar, bio and a list of linked accounts."

`NfcMode::all()` already returns `self::cases()`, so the admin mode selector picks
the new case up with no change.

### 2. Data: `nfc_profiles`

A new table, 1-to-1 with a card, following the reasoning already documented on
`nfc_contact_profiles` — these columns are mostly null for most cards and do not
belong on the hot `cards` row.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `card_id` | foreignId, unique, cascadeOnDelete | |
| `display_name` | string(120) nullable | "Uriel Papa" |
| `headline` | string(200) nullable | "Chief Executive Officer, Vite SEO Digital Advertising OPC" |
| `username` | string(60) nullable | "yurielyel" — rendered as plain text, never a link |
| `location` | string(100) nullable | "Philippines" |
| `tagline` | string(200) nullable | "Say my name" |
| `avatar_path` | string(255) nullable | server-generated relative path |
| `welcome_eyebrow` | string(60) nullable | "Heya, partners!" |
| `welcome_heading` | string(200) nullable | headline of the welcome panel |
| `welcome_body` | text nullable | max 600 chars, enforced in validation |
| `welcome_cta_label` | string(60) nullable | "Book a free consultation" |
| `welcome_cta_url` | string(500) nullable | validated with `SafeExternalUrl` |
| `brand_color` | string(7) nullable | `#RRGGBB`; **derived from the avatar**, not user input (section 15) |
| `timestamps` | | `updated_at` backs the "Last updated" row |

`App\Models\NfcProfile` mirrors `NfcContactProfile`: an explicit `$fillable`
allow-list that omits `card_id`, `avatar_path` and `brand_color` (these are set only
by the controller from a server-generated name), a `belongsTo(Card::class)`, and:

- `isMeaningful(): bool` — true when `display_name` is non-empty. A profile page
  with no name has nothing to show, matching how `NfcContactProfile::hasName()`
  gates contact mode.

`Card` gains `profile(): HasOne` and a `NfcMode::Profile` arm in
`nfcModeIsConfigured()` returning `(bool) $this->profile?->isMeaningful()`.

### 3. The accounts list reuses `nfc_links`

Profile mode does **not** get its own link table. It reads the same
`activeNfcLinks()` that Links mode uses, so switching between Links and Profile is
lossless and needs no data migration — the same links simply render richly instead
of as plain buttons.

### 4. Platform detection: `App\Services\LinkPlatform`

A pure, dependency-free service that maps a URL to a platform key. No database, no
network, so it is unit-testable in isolation.

```php
public static function forUrl(string $url): string   // 'x' | 'linkedin' | ... | 'web'
public static function label(string $key): string    // 'X', 'LinkedIn', ...
public static function displayUrl(string $url): string // 'x.com/its_uriel26'
```

Recognised hosts: `x.com`/`twitter.com`, `linkedin.com`, `tiktok.com`,
`github.com`, `facebook.com`/`fb.com`, `instagram.com`, `youtube.com`/`youtu.be`.
Anything else returns `web`, labelled with its own host.

**Host matching must be suffix-anchored.** The check is
`$host === $needle || str_ends_with($host, '.'.$needle)` after stripping a leading
`www.`. A naive `str_contains($host, 'linkedin.com')` would render the LinkedIn
mark for `linkedin.com.attacker.tld`, letting a hostile link borrow a trusted
brand's icon. That is a spoofing vector, not a cosmetic detail.

`displayUrl()` strips the scheme, a leading `www.`, and any trailing slash, so the
panel reads `x.com/its_uriel26` rather than the full URL — matching the reference
design and staying readable on a phone.

Icons live in a single Blade partial,
`resources/views/components/platform-icon.blade.php`, which switches on the
platform key and emits an inline SVG. Eight marks total: the seven brands plus a
globe fallback.

### 5. Image handling: extract `App\Services\ImageIngest`

`CardController::storeContactPhoto()` currently holds the hardened upload path —
decode with GD, cap dimensions, re-encode to strip EXIF and any smuggled payload,
store under a random server-generated name. Profile mode adds another upload (the
avatar), and copy-pasting security-critical code into a second place is how one of
the two eventually drifts.

Extract it as:

```php
public function store(UploadedFile $file, string $directory, int $maxEdge): string
```

Call sites: contact photo (`contact-photos`, 800) and profile avatar
(`profile-avatars`, 512). There is no cover upload — the cover is a translucent
wash of the brand colour, so the avatar is the only new image in this feature.

The avatar path additionally hands the decoded GD resource to
`DominantColor` (section 15) before it is freed, so the page's brand colour is
computed from the same decode rather than by reopening the stored file.

One behavioural improvement while extracting: the current code calls `abort_if(...,
422)`, which shows an admin a raw error page. The service throws a domain
exception instead, and the calling controller converts it into a normal validation
error on the file field, so a bad upload behaves like every other form error.

### 6. Public rendering

New view `resources/views/public/nfc-profile.blade.php`, and a new arm in
`NfcController::show()`:

```php
NfcMode::Profile => $this->showProfile($card),
```

`showProfile()` returns the existing `notReady($card)` response when there is no
profile or `isMeaningful()` is false — identical to how contact, phone and email
already degrade. The response goes through the existing `noStore()` helper, so the
page keeps its no-store and noindex headers.

**Profile mode has no public self-service setup.** It is admin-managed, like
contact/phone/email. `NfcController::store()` already refuses any mode other than
`Links`, so no change is needed there; a test will pin that behaviour.

#### Zero-JavaScript strategy

The public pages are deliberately script-free and this page keeps that almost
entirely:

- **Advanced details** — native `<details>`/`<summary>`.
- **`...` overflow menu** — a `<details>` with a CSS-positioned panel. Without JS
  it cannot close on outside-click; tapping the summary again closes it, which is
  an acceptable trade for shipping no script.
- **QR badge on the avatar** — a `<details>` wrapping
  `QrCodeService::svgForCard($card)`, which encodes the **`/q/` URL**. This is the
  code physically printed on the card, and it is deliberately *not* the profile
  URL — a card carries both an NFC tag and a printed QR, and the two resolve to
  different things. The popover shows the code alone, with no caption. Since
  nothing on screen distinguishes the two QRs, the difference is pinned by a test
  (see Testing) rather than by wording a visitor would have to read.
  `SVG_SIZE` is 1000, so the markup needs a wrapper with an explicit CSS width to
  render at badge scale.
- **Share** — a modal, described in section 11.

### 7. Admin surface

Profile editing gets its own controller, `Admin\CardProfileController`, rather than
being added to `Admin\CardController`. That file is already 457 lines covering QR,
links, mode, contact, phone, email, PIN and downloads; adding a fourth payload
type would push it past 600 and make it harder to reason about. The contact
endpoints can move the same way later, but that is not part of this work.

Routes, nested inside the existing `cards/{card}` group:

```
PATCH  /profile         → updateProfile
DELETE /profile         → deleteProfile
DELETE /profile/avatar  → deleteAvatar
```

Validation: `display_name` max 120, `headline` max 200, `username` max 60,
`location` max 100, `tagline` max 200, and `avatar` using the same rules as the
existing contact photo (`image`, `mimes:jpeg,png,webp`, `max:2048`,
`dimensions:max_width=4000,max_height=4000`).

There is no `brand_color` input. It is derived from the avatar (section 15).

Every text value passes through the same control-character strip already used for
contact fields, so nothing can smuggle a CR/LF into stored data.

The admin card page gets a "Profile" panel mirroring the existing Contact panel,
including a "Preview" link to the public page.

### 8. Fixing reset and the dashboard as part of this work

Shipping a fifth mode on top of the reset bugs found in the code review would give
the new mode the same data leak on day one, so both are in scope here.

**`resetNfc` becomes "clear the whole NFC side."** It deletes links, the contact
profile and its photo, the profile and its avatar, clears
`nfc_phone_number` and the three `nfc_email_*` columns, clears the branding
columns, and resets `nfc_mode` to `Links`. The surgical case is already served by
the existing per-mode delete actions, so making this one unambiguous removes the
current situation where the success message is only true in Links mode.

**`resetCard` becomes `resetNfc` plus the QR reset**, and its "Card fully reset"
message becomes true. Today it leaves the contact profile, phone, email and mode
untouched, so a reset card in Contact mode still serves the previous holder's name
and still returns their `.vcf` with a 200.

**The dashboard becomes mode-aware.** It currently derives NFC status purely from
active link count, so a card actively serving a contact card, phone, email or
profile displays "Unconfigured". `Card::nfcModeIsConfigured()` already exists and
is unused; the dashboard will use it. To avoid an N+1 across the 25-row page, the
query eager-loads `contactProfile` and `profile` with only the columns the status
check needs, alongside the existing `active_link_count` subquery. The five stat
counters are likewise rewritten to count against each card's current mode.

### 9. Theme

The structure is a **coloured page frame with light content cards** — never a
fully dark page. The cards are white with dark text; only the background, the
footer links and the overflow-menu trigger sit on the coloured field.

The frame colour itself is not fixed. It is extracted from the uploaded avatar and
normalised into a dark band, as described in section 15. The near-black used in the
first mockup is now only the fallback for cards without a usable photo.

This is the first public page that is not light-first, so the palette lives in this
view's own `:root` block rather than being pushed into a shared layout. The
existing pages stay untouched.

A rendered mockup of the approved design lives at
`docs/superpowers/specs/mockups/2026-08-24-nfc-profile.html`, with screenshots in
`shots/profile-v3*.png`. Its CSS is intended to transfer into the Blade view
directly. Append `#share`, `#about`, `#terms` or `#privacy` to the mockup URL to
see each overlay.

### 10. Public-facing copy and disclosure

**"Advanced details" shows the permanent URL and a last-updated date. It must not
show `card_number`.** The card number is an internal, sequential identifier;
publishing it tells any visitor roughly how many cards have been issued and where
theirs falls in that sequence. `routes/web.php` already states that public routes
expose "no card inventory, no admin surface and no internal IDs", and printing the
card number would contradict that for no user benefit.

The `...` overflow menu carries exactly two items: **Edit your profile**
(section 13) and **Copy link**.

Copy link copies the URL in place and raises a short "Link copied" toast. It does
*not* open the Share modal — routing a one-tap action through a modal that
contains its own copy button was redundant.

There is no "Report abuse". Gravatar's remaining items (Gravatar.com, Developer
API) are product-specific and have no equivalent here.

## Testing

Feature tests:

- Profile mode dispatches to the profile view; other modes are unaffected.
- Profile mode with no profile, or a profile with no `display_name`, renders the
  not-ready page rather than the links setup form.
- Public self-service `store()` still refuses Profile mode.
- Profile fields are HTML-escaped; a `<script>` in any field does not execute.
- The page carries no-store and noindex headers.
- `resetNfc` and `resetCard` clear every mode's payload, reset the mode, and delete
  the stored avatar and contact photo files.
- Dashboard status and all five counters are correct for a card in each of the five
  modes.

Unit tests for `LinkPlatform`:

- Each recognised host maps to its platform, including `www.` and regional
  subdomains such as `ph.linkedin.com`.
- `linkedin.com.attacker.tld` resolves to `web`, not `linkedin`.
- `displayUrl()` strips scheme, `www.` and trailing slash.

Unit tests for `ImageIngest`: output is a valid JPEG, oversized input is scaled to
the requested longest edge, and a non-image is rejected.

For the Share modal, QR split and vCard:

- The avatar badge QR encodes the `/q/` URL and the modal QR encodes the `/n/`
  URL. This is asserted directly, because the two being swapped is the most
  likely regression here and would be invisible on inspection.
- The public QR download returns a valid PNG and carries no-store headers.
- `profile.vcf` is version 3.0, contains `FN` from `display_name`, splits
  `headline` into `TITLE` and `ORG` on the first comma, and emits one `URL` per
  active link.
- A `headline` with no comma produces a `TITLE` and no `ORG`.
- CRLF and `;`/`,` in any profile field cannot forge a vCard property.

For the welcome block:

- Every welcome field is HTML-escaped on the public page.
- `welcome_cta_url` rejects a `javascript:` scheme and any non-http(s) scheme via
  `SafeExternalUrl`.
- The block is omitted entirely when no welcome fields are set, rather than
  rendering an empty panel.

For client self-service editing:

- A wrong PIN does not modify the profile and is rate limited after five attempts.
- A correct PIN writes the profile and records one `nfc_profile_edits` row
  containing the pre-edit snapshot.
- The admin revert action restores the snapshot exactly.
- The edit form is reachable only for cards in Profile mode.
- The client edit form cannot write `site_pages` content or any other card's data.

For brand colour:

- `DominantColor` on a fixture that is mostly one strong hue returns that hue.
- Every colour it returns is inside the dark band. This is the assertion the whole
  feature rests on: a value outside it makes the footer text unreadable, and a
  neon page is not something a test would otherwise catch.
- A grayscale fixture and a fully transparent fixture both fall back to the
  default rather than inventing a hue.
- A pale fixture and a neon fixture both return a colour whose hue matches the
  input but whose lightness has been pulled into the band.
- `brand_color` is not mass-assignable: posting it to the admin and client update
  endpoints leaves the stored value untouched.
- Replacing the avatar recomputes the colour; deleting the avatar clears it and
  the page falls back to the default without emitting an empty custom property.
- Whatever ends up stored, it always matches `/^#[0-9a-f]{6}$/`, since the value
  is interpolated into a `style` attribute.

For the global pages:

- All three modals render their seeded content.
- `body` is escaped: a `<script>` in the admin-authored text does not execute.
- An unauthenticated request to `/admin/site-pages` is rejected.

### 11. Share modal

Tapping Share opens a modal containing, top to bottom: a QR of the **profile URL**
(`/n/{public_id}`) on a dark stage, a "Download QR" button, a "Share via Email"
row, an "Add to contacts (vCard)" row, and a link bar showing the URL with a Copy
button.

The overlay is **centred both horizontally and vertically** (`align-items:center`),
with `max-height:calc(100dvh - 48px)` and internal scrolling so a long modal still
fits a short screen.

**Implemented with CSS `:target`, not JavaScript.** The Share control is
`<a href="#share">`, the overlay is `#share` with `display:none` flipped by
`.modal:target`, and the close button is `<a href="#">`. This keeps the page
script-free and means the modal survives with JS disabled. The only remaining
script on the page is the clipboard call behind the two "Copy link" controls; its
base state is the URL rendered as selectable text, so nothing is lost without it.

Endpoint notes:

- **Download QR** is a new *public* route, `GET /n/{card}/qr.png`, returning the
  profile-URL QR. QR downloads are currently admin-only; this one is public by
  necessity, and it exposes nothing the visitor cannot already see, since they are
  looking at the page the code encodes.
- **Share via Email** is a plain `mailto:` with a prefilled subject and the profile
  URL in the body. No backend.
- **Add to contacts (vCard)** is covered in section 12.

**The QR embeds the avatar in its centre.** `QrCodeService` currently documents
level M with "No logo is embedded in V1, so we do not need Q or H." Punching a
hole in the middle destroys modules, so the profile QR is rendered at level **H**
(~30% recovery) and the avatar is capped at roughly 22% of the code's area. This
is a change to `QrCodeService`, added as a new method rather than by altering the
existing card-QR path, so printed card QRs keep their current level-M density.

### 12. vCard from profile data

"Add to contacts" serves `GET /n/{card}/profile.vcf`, built by the existing
`VCardBuilder` from **profile** fields rather than a contact profile, since Profile
mode has no contact record:

- `FN` / `N` from `display_name`
- `TITLE` and `ORG` from `headline`, split on the first comma when one is present,
  otherwise the whole string becomes `TITLE`
- `URL` lines from the card's active links
- `NOTE` from `tagline`
- `PHOTO` is omitted; embedding the avatar would bloat the file for little gain

`VCardBuilder::build()` currently takes a `NfcContactProfile`. It grows a sibling
method that accepts the profile model, sharing the same private `esc()` and
`structured()` helpers so the CR/LF and RFC 2426 escaping rules stay in exactly
one place.

### 13. Client self-service editing

The `...` menu links to `GET /n/{card}/edit`, which asks for the activation PIN
and then allows the cardholder to change their own profile text, links and avatar.
The brand colour and the global ViteSEO pages are **not** on this form — those stay
admin-only.

**This is gated by the universal PIN `000000`, at the product owner's explicit
direction.** The consequence is recorded here so it is not rediscovered later:
that PIN is identical on every card, is shown in the admin UI, and is documented
in `CardGenerationService` as "an anti-accident guard, not an authentication
secret". Anyone who reaches a card's URL can therefore rewrite that card's
profile — including repointing every link while leaving the cardholder's name and
photo in place. The permanent URL is printed on physical plastic, so a cardholder
cannot rotate away from an abuse incident.

Three mitigations are in scope, none of which change the PIN decision:

1. **Rate limiting.** The edit endpoint reuses the existing
   `RateLimiter`-per-`card|ip` pattern from `NfcController::store()`.
2. **Audit trail.** A new `nfc_profile_edits` table records `card_id`, IP, user
   agent, timestamp and a JSON snapshot of the values *before* the edit. This is
   what makes an incident diagnosable instead of invisible.
3. **Admin revert.** Because the snapshot exists, the admin card page gets a
   "Revert to previous version" action. Recovery does not depend on anyone having
   noticed the change quickly.

### 14. Scale

The reference design reads larger than the first mockup. Confirmed values, taken
from the approved revision-2 render:

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
| modal max-width | 520px |

The column was widened from 480px to 680px specifically to stop the page reading
as a narrow strip inside a large field of background colour on desktop. On a phone
it is full-bleed either way.

### 15. Brand colour

The page background is **not** a fixed near-black. It is derived from the
cardholder's uploaded avatar, so the page automatically dresses itself in the
colours of the photo on it. Nobody picks a colour: there is no colour picker in
the admin and none on the client edit form.

`brand_color` is therefore a **derived, cached column**, not user input. Like
`avatar_path` it is omitted from `$fillable` and written only by the service
below, so no request can set it directly. It is recomputed whenever the avatar is
uploaded or replaced, and cleared when the avatar is deleted. It is never computed
per request — decoding an image on every public page view would be absurd for a
value that changes only when the photo does.

#### Extraction: `App\Services\DominantColor`

A separate service from `ImageIngest`, because finding a colour and safely
storing an upload are unrelated concerns. It runs on the already-decoded GD
resource that `ImageIngest` produces, so the file is never read twice.

1. Downscale to 32×32. Sampling every pixel of a 512px avatar buys no accuracy.
2. Discard near-white, near-black and very low-saturation pixels. Studio photos
   are mostly backdrop and skin; without this step the "dominant" colour of
   almost every portrait is beige.
3. Bucket the survivors into a coarse histogram (4 bits per channel), take the
   most populous bucket, then average the actual pixels inside it for a precise
   value rather than the bucket's centre.
4. **Normalise lightness into a dark band.** Keep the hue and most of the
   saturation; force HSL lightness to roughly 18–24%.

Step 4 is the one that makes this feature safe. A colour lifted straight out of a
photo is frequently pale, neon, or both — a bright yellow shirt would yield a
neon yellow page with invisible white footer text and white cards dissolving into
the background. Normalising the lightness means the extracted hue is honoured
while the page stays in the same tonal family as the mockup no matter what photo
is uploaded. A pale pink avatar produces a deep rose page, not a pink one.

It also means **contrast is correct by construction rather than by calculation.**
Because every stored `brand_color` is guaranteed dark, `--on-brand` is a fixed
light value and no luminance branch is needed. The luminance check that an
arbitrary user-chosen colour would have required disappears along with the
colour picker.

Fallbacks, all resolving to the default near-black `#0f1713`:

- No avatar uploaded.
- Grayscale or near-grayscale avatar, where step 2 leaves too few pixels to be
  meaningful. Forcing a hue out of a black-and-white photo invents information.
- Any GD failure during extraction. A page that renders in the default colour is
  a much better outcome than a page that does not render.

#### Emitted properties

| Property | Source |
|---|---|
| `--brand` | `brand_color`, or the default when null |
| `--brand-soft` | same RGB at ~34% alpha, for the cover |
| `--on-brand` | fixed light value, safe because `--brand` is always dark |

The cover is `--brand-soft`, so it reads as a lighter wash of the same hue rather
than a separate decoration. The avatar placeholder shown when there is no photo
uses the solid colour.

The white cards still carry a hairline `rgba(0,0,0,.06)` border. It is not
strictly required now that the background is guaranteed dark, but it keeps the
card edges crisp against a saturated colour.

If a client ever ends up with an extracted colour they dislike, the recovery is a
single nullable override column and one admin field. That is deliberately not
built now — the point of this design is that it needs no input.

### 16. Global ViteSEO pages

"What is this?", "Terms of Service" and "Privacy Policy" open `:target` modals
whose content is authored by ViteSEO and **applies to every card at once**. They
are not per-card fields and a cardholder can never edit them.

A new table, `site_pages`, holds one row per page:

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `key` | string(40) unique | `about`, `terms`, `privacy` |
| `title` | string(120) | modal heading |
| `body` | text | plain text; blank lines become paragraphs |
| `timestamps` | | |

A migration seeds the three rows so the modals are never empty. Editing lives in a
new admin section at `/admin/site-pages`, behind the existing `auth` middleware —
deliberately *not* under `cards/{card}`, because this content is global and
attaching it to a card route would imply otherwise.

**`body` is plain text, not HTML.** It is escaped on output and split on blank
lines into paragraphs. Accepting HTML here would put a stored-XSS sink on every
public card page in the system for the sake of formatting that nobody has asked
for yet. If rich formatting is needed later, Markdown rendered through a
sanitiser is the way in, and that is a separate decision.

## Delivery order

Six increments, each independently shippable and testable. The first does not
depend on any of the others and fixes live bugs, so it lands first.

1. **Reset and dashboard correctness** (section 8, minus the Profile-specific
   parts). Makes `resetNfc`/`resetCard` honest for the four existing modes and
   makes the dashboard mode-aware. No new tables, no new UI. This is the smallest
   change with the highest value, since it closes an existing data leak.
2. **Profile mode data and rendering** (sections 1–6, 14). Migration, model, enum
   case, `LinkPlatform`, `ImageIngest` extraction, the public view at the approved
   scale. Reachable by setting the mode directly; no admin UI yet.
3. **Share modal, QR split and vCard** (sections 11–12). The `:target` modal, the
   level-H avatar QR method on `QrCodeService`, the public QR download route, and
   the profile vCard.
4. **Global ViteSEO pages** (section 16). The `site_pages` table with seeded rows,
   the three footer modals, and the `/admin/site-pages` editor. Self-contained and
   touches no card data.
5. **Admin surface** (section 7), including the welcome-block fields and the
   `DominantColor` extraction wired into avatar upload, plus wiring Profile into
   the reset and dashboard logic delivered in step 1.
6. **Client self-service editing** (section 13). The PIN-gated edit form, rate
   limiting, the `nfc_profile_edits` audit table and the admin revert action.
   Deliberately last: it is the only increment that lets an unauthenticated
   visitor write to an already-configured card, so it ships once everything it
   touches is settled and tested.

## Consequences

- `nfc_links` is now shared by two modes. Deleting a link affects both Links and
  Profile rendering for that card. This is intended.
- `resetNfc` is more destructive than it is today. It is already labelled as a
  reset and sits behind a confirmation, and the per-mode deletes cover the
  narrower case.
- A card's two QR codes now both appear in the UI and encode different URLs, with
  nothing on screen saying which is which. A test is the only thing keeping them
  from being swapped.
- The page's background colour is now a function of an uploaded photo, so a bad
  avatar produces a bad page with nobody having made a choice. The lightness
  normalisation in section 15 is what keeps that from being a visual failure, and
  there is no manual override by design.
- `site_pages` content is global. One bad edit there is visible on every card in
  the system at once, so that editor is admin-only and its output is escaped.
- Profile mode becomes the only mode where a visitor can edit an
  already-configured card. Section 13 records the accepted risk and the three
  mitigations that bound it.
- One inline script remains, behind "Copy link", purely as progressive
  enhancement. Everything else on the page — including the Share modal — works
  with JavaScript disabled.

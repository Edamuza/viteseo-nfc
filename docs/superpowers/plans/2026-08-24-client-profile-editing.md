# Client Profile Editing and Revert Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete Increment 6 with PIN-gated client Profile editing, rate limiting, immutable audit snapshots, and admin recovery.

**Architecture:** A dedicated public controller unlocks one card in the session after PIN verification, then accepts an explicit allow-list of client-owned profile fields, links, and avatar. Every successful update stores the complete pre-edit Profile/link snapshot in `nfc_profile_edits`; the admin restores the newest snapshot transactionally.

**Tech Stack:** Laravel 12, Eloquent, session auth gate, RateLimiter, GD upload pipeline, Blade, PHPUnit.

## Global Constraints

- Editing exists only while the card's current mode is Profile.
- The accepted universal PIN remains `000000`; it is an anti-accident guard, not authentication.
- Wrong PIN attempts are limited to five per card + client IP for 15 minutes.
- Session unlock is scoped to one public ID and expires after 30 minutes.
- Client fields are only `display_name`, `headline`, `username`, `location`, `tagline`, avatar, and links.
- Clients cannot write welcome fields, brand colour, global site pages, card IDs, or another card's links.
- Each successful edit records the values before the write, including profile, avatar/colour, and all links.
- Old avatar files referenced by an audit snapshot are retained until restore.
- Admin restore is atomic and removes the superseded current avatar only after database success.
- No commit is created unless explicitly requested.

---

### Task 1: Audit model and snapshot contract

**Files:**
- Create: `database/migrations/2026_08_24_000003_create_nfc_profile_edits_table.php`
- Create: `app/Models/NfcProfileEdit.php`
- Modify: `app/Models/Card.php`
- Create: `tests/Feature/NfcProfileEditTest.php`

**Interfaces:**
- Produces: `Card::profileEdits(): HasMany`
- Produces: `NfcProfileEdit::$casts['snapshot'] = 'array'`

- [ ] Write failing tests for the audit schema, cascade deletion, JSON cast,
and newest-first relation.
- [ ] Run the new test file; expect missing table/model failures.
- [ ] Create columns: `card_id` cascade FK, `ip_address` length 45,
nullable `user_agent` length 500, JSON `snapshot`, timestamps, plus
`card_id,created_at` index.
- [ ] Implement the model and Card relation; run focused tests to green.

---

### Task 2: Profile-only PIN unlock

**Files:**
- Create: `app/Http/Controllers/Public/NfcProfileEditController.php`
- Create: `resources/views/public/nfc-profile-edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/public/nfc-profile.blade.php`
- Modify: `tests/Feature/NfcProfileEditTest.php`

**Interfaces:**
- Produces: `GET /n/{card}/edit`, route `nfc.profile.edit`
- Produces: `POST /n/{card}/edit/unlock`, route `nfc.profile.edit.unlock`

- [ ] Write failing tests: only Profile mode reaches the page; locked page has
only the PIN form; wrong PIN changes nothing; the sixth wrong attempt is rate
limited; correct PIN redirects to the unlocked editor; PIN is never echoed.
- [ ] Run focused tests and confirm missing routes.
- [ ] Implement a distinct key `nfc-profile-edit|{public_id}|{ip}`, max 5,
900-second decay. Store an unlock timestamp under a per-card session key and
accept it for 1800 seconds.
- [ ] Return all edit responses with no-store/noindex headers.
- [ ] Change the overflow Edit link to the named route.
- [ ] Run unlock tests to green.

---

### Task 3: Client update, links, avatar, and pre-edit snapshot

**Files:**
- Modify: `app/Http/Controllers/Public/NfcProfileEditController.php`
- Modify: `resources/views/public/nfc-profile-edit.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/NfcProfileEditTest.php`

**Interfaces:**
- Produces: `PATCH /n/{card}/edit`, route `nfc.profile.edit.update`

- [ ] Write failing tests that a correct unlocked update changes the five client
text fields, replaces active links, handles avatar upload/removal, and writes
exactly one audit row containing the pre-edit profile and all links.
- [ ] Add malicious input tests for `welcome_*`, `brand_color`, `card_id`,
`site_pages`, and another card's IDs/data; assert every protected value remains
unchanged.
- [ ] Normalize/filter fully blank link rows before validation, then validate at
most 12 rows with title max 120 and `SafeExternalUrl`.
- [ ] Capture this structure before writing:

```php
[
    'profile' => null|[
        'display_name' => ..., 'headline' => ..., 'username' => ...,
        'location' => ..., 'tagline' => ..., 'welcome_eyebrow' => ...,
        'welcome_heading' => ..., 'welcome_body' => ...,
        'welcome_cta_label' => ..., 'welcome_cta_url' => ...,
        'avatar_path' => ..., 'brand_color' => ...,
    ],
    'links' => [
        ['title' => ..., 'url' => ..., 'sort_order' => ..., 'is_active' => ...],
    ],
]
```

- [ ] In one DB transaction create the audit row, persist the explicitly allowed
profile fields, and replace this card's links. On failure remove only a newly
stored avatar; do not delete the previous avatar because the snapshot may restore it.
- [ ] Render a multipart mobile editor with existing fields/links, one blank
link row, progressive Add-link JavaScript, and avatar remove checkbox.
- [ ] Run update/security tests to green.

---

### Task 4: Admin restore of the newest client edit

**Files:**
- Modify: `app/Http/Controllers/Admin/CardProfileController.php`
- Modify: `app/Http/Controllers/Admin/CardController.php`
- Modify: `resources/views/admin/card.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/NfcProfileEditTest.php`

**Interfaces:**
- Produces: `POST /admin/cards/{card}/profile/revert`, route `admin.cards.profile.revert`

- [ ] Write failing tests that only admins can restore, the newest snapshot
restores every profile/link value exactly, the audit row is consumed, the
superseded avatar is deleted, and a card with no history is harmless.
- [ ] Eager-load or query the latest edit for the admin Profile panel and show
its timestamp/IP with a confirmed Revert action.
- [ ] Restore inside a transaction: recreate/delete the profile according to
the snapshot, replace all links from the snapshot scoped through the card
relation, and delete the consumed edit row. After commit, remove the superseded
current avatar only when it differs from the restored path.
- [ ] Run:

```bash
php artisan test tests/Feature/NfcProfileEditTest.php
php artisan test tests/Feature/AdminProfilePanelTest.php tests/Feature/AdminProfileManagementTest.php
php artisan test
git diff --check
```

Expected: all tests pass and no diff errors.

No commit is created unless explicitly requested.

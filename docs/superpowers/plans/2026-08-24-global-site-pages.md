# Global ViteSEO Site Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete Increment 4 with globally managed What is this, Terms of Service, and Privacy Policy modal content.

**Architecture:** Store exactly three globally keyed plain-text pages in `site_pages`, seeded by the migration. Load them once for Profile rendering, escape each paragraph through Blade, and expose a small authenticated admin editor outside card routes.

**Tech Stack:** Laravel 12, Eloquent, Blade, PHPUnit.

## Global Constraints

- Keys are exactly `about`, `terms`, and `privacy`.
- `key` is immutable from admin requests.
- Body is plain text only; output always uses escaped Blade expressions.
- Blank lines split body into separate paragraphs.
- Content is global and never nested under a card route.
- The admin editor is protected by existing `auth` middleware.
- No commit is created unless explicitly requested.

---

### Task 1: Site-page data contract and migration seed

**Files:**
- Create: `database/migrations/2026_08_24_000002_create_site_pages_table.php`
- Create: `app/Models/SitePage.php`
- Create: `tests/Feature/SitePagesTest.php`

**Interfaces:**
- Produces: `SitePage::paragraphs(): array`
- Produces: route binding by immutable `key`

- [ ] Write failing tests asserting three migration-seeded rows, unique keys,
fillable `title`/`body` only, and blank-line paragraph splitting.
- [ ] Run `php artisan test tests/Feature/SitePagesTest.php`; expect missing table/model failures.
- [ ] Create the table (`id`, unique `key` length 40, `title` length 120,
`body` text, timestamps) and insert all three defaults inside `up()`.
- [ ] Implement `SitePage` with `$fillable = ['title', 'body']`,
`getRouteKeyName(): string` returning `key`, and:

```php
public function paragraphs(): array
{
    return array_values(array_filter(
        preg_split('/\R[ \t]*\R+/', trim((string) $this->body)) ?: [],
        fn (string $paragraph) => $paragraph !== ''
    ));
}
```

- [ ] Run the focused test file; expect data tests to pass.

---

### Task 2: Public footer and escaped modal rendering

**Files:**
- Modify: `app/Http/Controllers/Public/NfcController.php`
- Modify: `resources/views/public/nfc-profile.blade.php`
- Modify: `tests/Feature/SitePagesTest.php`

**Interfaces:**
- Consumes: `SitePage::paragraphs()`

- [ ] Write failing tests for three footer links and `#about`, `#terms`,
`#privacy` dialogs with seeded content.
- [ ] Add a malicious body containing `<script>` plus multiple paragraphs and
assert raw script is absent, escaped text is present, and each paragraph gets
its own `<p>`.
- [ ] Run focused tests; expect missing modal failures.
- [ ] In `showProfile()`, load the three rows keyed by `key` and pass
`sitePages` to the view.
- [ ] Add footer nav, `.prose`, and three `:target` modals. Render with:

```blade
@foreach ($page->paragraphs() as $paragraph)
  <p>{{ $paragraph }}</p>
@endforeach
```

- [ ] Run focused tests; expect public rendering tests to pass.

---

### Task 3: Authenticated admin editor

**Files:**
- Create: `app/Http/Controllers/Admin/SitePageController.php`
- Create: `resources/views/admin/site-pages.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `tests/Feature/SitePagesTest.php`
- Modify: `tests/Feature/AdminTest.php`

**Interfaces:**
- Produces: `GET /admin/site-pages`, route `admin.site-pages.index`
- Produces: `PATCH /admin/site-pages/{sitePage:key}`, route `admin.site-pages.update`

- [ ] Write failing tests that unauthenticated index/update requests redirect to
admin login, an admin can update title/body, crafted `key` input cannot rename
the row, validation rejects title over 120, and the nav exposes Site Pages.
- [ ] Run focused tests; expect missing route failures.
- [ ] Add `SitePageController::index()` returning all three pages in key order
and `update()` validating only:

```php
[
    'title' => ['required', 'string', 'max:120'],
    'body' => ['required', 'string'],
]
```

- [ ] Add routes inside the authenticated admin group, add the topbar nav item,
style textareas consistently, and create one PATCH form per page.
- [ ] Add `/admin/site-pages` to `AdminTest::adminGetRoutes()`.
- [ ] Run:

```bash
php artisan test tests/Feature/SitePagesTest.php tests/Feature/AdminTest.php
php artisan test
git diff --check
```

Expected: all tests pass and no diff errors.

No commit is created unless explicitly requested.

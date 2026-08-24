# Advanced Details Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Profile page's inline Advanced details disclosure with a centred, CSS-only modal containing the welcome panel and technical details.

**Architecture:** Keep the change inside the existing public Profile Blade view. Reuse the project's approved `:target` overlay pattern so no controller, route, JavaScript, or new dependency is required. Use `LinkPlatform::safeHref()` as a read-side backstop for the welcome CTA.

**Tech Stack:** Laravel Blade, CSS `:target`, PHPUnit feature tests.

## Global Constraints

- The trigger remains in its current position and keeps the approved chevron.
- The modal is centred horizontally and vertically, has a 520px maximum width, and scrolls internally on short screens.
- All profile-authored text uses escaped Blade output.
- Only HTTP(S) CTA destinations may become live links.
- No JavaScript and no new dependency.
- Do not expose `card_number`.

---

### Task 1: Advanced details modal

**Files:**
- Modify: `tests/Feature/NfcProfileTest.php`
- Modify: `resources/views/public/nfc-profile.blade.php`

**Interfaces:**
- Consumes: `NfcProfile` welcome fields and `LinkPlatform::safeHref(string $url): string`
- Produces: the `#advanced-details` CSS-target modal on the public Profile page

- [ ] **Step 1: Write failing feature tests**

Add focused tests to `NfcProfileTest` which render a real Profile card and prove:

```php
public function test_advanced_details_opens_a_named_modal_instead_of_an_inline_disclosure(): void
{
    $card = $this->profileCard();
    $body = $this->get('/n/'.$card->public_id)->assertOk()->getContent();

    $this->assertStringContainsString('href="#advanced-details"', $body);
    $this->assertStringContainsString('class="modal" id="advanced-details"', $body);
    $this->assertStringContainsString('role="dialog"', $body);
    $this->assertStringContainsString('aria-labelledby="advanced-details-title"', $body);
    $this->assertStringContainsString('href="#" aria-label="Close"', $body);
    $this->assertStringNotContainsString('<details class="adv">', $body);
}
```

Add separate tests for:

- the exact chevron remaining inside the trigger;
- `.modal:target{display:flex}` plus `align-items:center`,
  `justify-content:center`, and `max-height:calc(100dvh - 48px)`;
- Permanent URL and Last updated remaining inside the modal;
- all welcome fields rendering when populated;
- welcome markup being omitted when every welcome field is empty;
- HTML escaping in eyebrow, heading, body, and CTA label;
- `javascript:` CTA destinations not becoming an anchor;
- an HTTPS CTA rendering as a live external link.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan view:clear
php artisan test --filter=NfcProfileTest
```

Expected: the new modal tests fail because the page still renders
`<details class="adv">` and has no `#advanced-details` modal.

- [ ] **Step 3: Implement the modal styles**

Replace the `details.adv` rules with a pill-link trigger and reusable overlay
rules:

```css
.adv-trigger-wrap{margin:6px 0 0;text-align:center}
.adv-trigger{display:inline-flex;align-items:center;gap:8px;...}
.modal{position:fixed;inset:0;z-index:50;display:none;align-items:center;
 justify-content:center;padding:24px;background:rgba(0,0,0,.62)}
.modal:target{display:flex}
.modal-box{width:min(520px,100%);max-height:calc(100dvh - 48px);
 overflow-y:auto;background:var(--card);border-radius:var(--radius)}
```

Include a modal header, close control, welcome treatment, and light key/value
details card. Preserve the page's mobile-safe sizing.

- [ ] **Step 4: Replace the inline disclosure with the modal**

The trigger:

```blade
<div class="adv-trigger-wrap">
  <a class="adv-trigger" href="#advanced-details">
    <svg ...>...</svg>
    Advanced details
  </a>
</div>
```

The modal:

```blade
<div class="modal" id="advanced-details" role="dialog"
     aria-modal="true" aria-labelledby="advanced-details-title">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="advanced-details-title">Advanced details</h2>
      <a class="modal-x" href="#" aria-label="Close">&#10005;</a>
    </div>

    {{-- Render the welcome block only when at least one welcome field is set. --}}
    {{-- Render CTA as <a> only when label and safeHref() are both usable. --}}

    <div class="adv-body">
      <div class="kv">Permanent URL ...</div>
      <div class="kv">Last updated ...</div>
    </div>
  </div>
</div>
```

- [ ] **Step 5: Run focused and full verification**

Run:

```bash
php artisan view:clear
php artisan test --filter=NfcProfileTest
php artisan test
```

Expected: all tests pass with no warnings or skips.

Then mutation-check the coverage:

1. Change the trigger target to `#wrong`; the modal identity test must fail.
2. Remove `align-items:center`; the centring test must fail.
3. Restore `<details class="adv">`; the inline-disclosure absence test must fail.
4. Change one welcome field to raw `{!! !!}` output; the escaping test must fail.
5. Bypass `safeHref()` for the CTA; the unsafe-CTA test must fail.

Restore every mutation and rerun the focused test.

No commit is created unless the user explicitly requests one.

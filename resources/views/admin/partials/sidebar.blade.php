{{-- The left rail, ported from viteseo-platform2.0 (core/layout/Sidebar.tsx):
     same 260px / 72px widths, the same near-black surface, the same teal
     active pill, the same chevron hanging off the edge, the same off-canvas
     drawer on a phone. What is NOT ported is the machinery around it - React
     state, a context provider, localStorage per user id - because a four-item
     nav does not need any of it. The drawer is a checkbox; only the desktop
     rail asks for JavaScript. --}}
@php
  $adminUser = auth()->user();
  // Two letters at most, from the first and last word of the name.
  $words = preg_split('/\s+/', trim((string) ($adminUser->name ?? 'Admin')), -1, PREG_SPLIT_NO_EMPTY) ?: ['Admin'];
  $adminInitials = mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
@endphp
<aside class="sidebar" id="admin-sidebar">

  {{-- Desktop only, and only once the script has run: without it the button
       would be a control that does nothing. --}}
  <button class="nav-collapse" id="nav-collapse" type="button" aria-controls="admin-sidebar" aria-expanded="true" aria-label="Collapse sidebar">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
  </button>

  <div class="side-brand">
    <a class="brand" href="{{ route('admin.dashboard') }}" aria-label="NFC Cards home">
      <span class="brand-mark" aria-hidden="true">N</span>
      <span class="brand-name rail-hide">NFC Cards</span>
    </a>
    {{-- Closes the phone drawer. Absent on desktop, where there is no drawer. --}}
    <label class="side-close" for="navstate" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </label>
  </div>

  <nav class="side-nav" aria-label="Admin sections">
    {{-- Cards covers the card detail pages too: a card page is reached from
         this row and belongs under it. --}}
    <a href="{{ route('admin.dashboard') }}" title="Cards"
       @class(['nrow', 'on' => request()->routeIs('admin.dashboard') || request()->routeIs('admin.cards.*')])>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>
      </svg>
      <span class="rail-hide">Cards</span>
    </a>

    <a href="{{ route('admin.nfc-tools') }}" title="NFC Tools"
       @class(['nrow', 'on' => request()->routeIs('admin.nfc-tools')])>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M6 8.32a7.43 7.43 0 0 1 0 7.36"/><path d="M9.46 6.21a11.76 11.76 0 0 1 0 11.58"/>
        <path d="M12.91 4.1a15.91 15.91 0 0 1 .01 15.8"/><path d="M16.37 2a20.16 20.16 0 0 1 0 20"/>
      </svg>
      <span class="rail-hide">NFC Tools</span>
    </a>

    <a href="{{ route('admin.site-pages.index') }}" title="Site Pages"
       @class(['nrow', 'on' => request()->routeIs('admin.site-pages.*')])>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>
        <path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>
      </svg>
      <span class="rail-hide">Site Pages</span>
    </a>

    <a href="{{ route('admin.sticker-templates.index') }}" title="Sticker templates"
       @class(['nrow', 'on' => request()->routeIs('admin.sticker-templates.*')])>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
      </svg>
      <span class="rail-hide">Sticker templates</span>
    </a>
  </nav>

  <div class="side-foot">
    <div class="side-foot-row">
      <span class="ava" aria-hidden="true">{{ $adminInitials }}</span>
      <div class="who rail-hide">
        <p class="who-n">{{ $adminUser->name ?? 'Admin' }}</p>
        <p class="who-r">{{ $adminUser->email ?? '' }}</p>
      </div>
      <form method="POST" action="{{ route('admin.logout') }}">@csrf
        <button class="signout" type="submit" title="Sign out" aria-label="Sign out">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>
          </svg>
        </button>
      </form>
    </div>
  </div>
</aside>

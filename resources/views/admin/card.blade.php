@extends('layouts.admin')
@section('title',$card->card_number)
@section('body')

@php
  $links      = $card->nfcLinks;
  $active     = $links->where('is_active', true);
  $qrOn       = $card->qrIsConfigured();
  $nfcCount   = $active->count();
  $nfcReady   = $card->nfcModeIsConfigured();
  $nfcLabel   = ! $nfcReady
      ? 'Unconfigured'
      : ($card->nfcMode() === \App\Enums\NfcMode::Links
          ? ($nfcCount === 1 ? 'Active' : $nfcCount.' Links')
          : $card->nfcMode()->label());
@endphp

{{-- ══════════════ HEADER ══════════════ --}}
<div class="page-head">
  <div class="row" style="justify-content:space-between;align-items:flex-start">
    <div>
      <h1>{{ $card->card_number }}</h1>
      <p>Public ID <code>{{ $card->public_id }}</code></p>
      <div class="row row-tight" style="margin-top:10px">
        <span class="badge {{ $qrOn ? 'b-ok' : 'b-off' }}">QR&nbsp;&middot;&nbsp;{{ $qrOn ? 'Active' : 'Unconfigured' }}</span>
        <span class="badge {{ $nfcReady ? 'b-ok' : 'b-off' }}">
          NFC&nbsp;&middot;&nbsp;{{ $nfcLabel }}
        </span>
        <span class="badge b-off">Created {{ $card->created_at->format('Y-m-d') }}</span>
      </div>
    </div>
    <div class="row row-tight" style="flex-wrap:nowrap">
      {{-- The same back arrow the owner screens use, so both sides of the
           system point the way out the same way. --}}
      <a class="btn btn-sm" href="{{ route('admin.dashboard') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 19 3 12l7-7"/><path d="M3 12h18"/></svg>
        All cards
      </a>
      {{-- Viewing and editing are different jobs. This card belongs to a
           customer and is live; opening it should not put a cursor in it. --}}
      @if ($editing)
        <a class="btn btn-sm" href="{{ route('admin.cards.show', $card->public_id) }}">Done editing</a>
      @else
        <a class="btn btn-sm btn-p" href="{{ route('admin.cards.show', $card->public_id) }}?edit=1">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
          Edit card
        </a>
      @endif
    </div>
  </div>
</div>

{{-- ══════════════ QR ══════════════ --}}
<div class="panel">
  <h2>QR code</h2>

  <div class="qr-layout">
    {{-- Preview is intentionally small: it is for scanning off-screen with a
         phone, not for print. Downloads are unaffected and remain full quality. --}}
    <figure class="qr-preview">
      <div class="qr-plate">{!! $qrSvg !!}</div>
      <figcaption class="small muted">Preview only &middot; downloads are full quality</figcaption>
    </figure>

    <div class="qr-detail">
      <label class="fld">Permanent QR URL</label>
      <div class="row row-tight" style="margin-bottom:14px">
        <code id="qrurl" style="flex:1;min-width:0">{{ $card->qrUrl() }}</code>
      </div>
      <div class="row row-tight" style="margin-bottom:18px">
        <button class="btn btn-sm" type="button" onclick="copyText('qrurl',this)">Copy URL</button>
        <a class="btn btn-sm" href="{{ route('admin.cards.qr.svg', $card->public_id) }}">Download SVG</a>
        <a class="btn btn-sm" href="{{ route('admin.cards.qr.png', $card->public_id) }}">Download PNG</a>
        <a class="btn btn-sm" href="{{ route('admin.cards.sticker.download', $card->public_id) }}">Download sticker template</a>
      </div>
      @if ($activeStickerTemplate)
        <p class="small muted" style="margin:-12px 0 18px">Uses {{ $activeStickerTemplate->name }}. <a href="{{ route('admin.sticker-templates.index') }}">Change the active template</a></p>
      @endif

      <label class="fld">Current destination</label>
      @if ($qrOn)
        <p class="small" style="margin:0 0 14px"><code>{{ $card->qr_destination }}</code></p>
      @else
        <p class="small muted" style="margin:0 0 14px">Not configured &mdash; the sticker still prints, and scans show the setup page until a destination is set.</p>
      @endif

      <form method="POST" action="{{ route('admin.cards.qr.set', $card->public_id) }}">
        @csrf @method('PATCH')
        <fieldset @disabled(! $editing)>
          <label class="fld" for="qrdest">{{ $qrOn ? 'Change' : 'Set' }} destination</label>
          <div class="row row-tight">
            <input id="qrdest" type="url" name="destination" placeholder="https://example.com"
                   value="{{ $card->qr_destination }}" style="flex:1;min-width:200px" required>
            <button class="btn btn-p" type="submit">Save</button>
            @if ($qrOn)
              <button class="btn btn-d" type="submit" form="reset-qr">Reset QR</button>
            @endif
          </div>
        </fieldset>
      </form>
      @if ($qrOn)
        <form id="reset-qr" method="POST" action="{{ route('admin.cards.qr.reset', $card->public_id) }}"
              onsubmit="return confirm('Reset the QR destination?\n\nThe printed QR code and its URL stay valid — the next scan shows the setup page again. NFC links are not affected.')">
          @csrf @method('DELETE')
        </form>
      @endif
    </div>
  </div>
</div>

{{-- ══════════════ NFC ══════════════ --}}
@php
  $mode    = $card->nfcMode();
  // Two unrelated one-to-one rows: $profile is the vCard behind Contact mode,
  // $nfcProfile is the page behind Profile mode.
  $profile    = $card->contactProfile;
  $nfcProfile = $card->profile;
  $M          = \App\Enums\NfcMode::class;
@endphp

<div class="panel">
  <h2>NFC</h2>

  <label class="fld">Permanent NFC URL</label>
  <div class="row row-tight" style="margin-bottom:12px">
    <code id="nfcurl" style="flex:1;min-width:0">{{ $card->nfcUrl() }}</code>
  </div>
  <div class="row row-tight" style="margin-bottom:6px">
    <button class="btn btn-sm" type="button" onclick="copyText('nfcurl',this)">Copy URL</button>
    <a class="btn btn-sm" href="{{ route('admin.nfc-tools') }}?card={{ $card->public_id }}">Program NFC</a>
  </div>
  <div class="note" style="margin-top:12px">
    <strong>The tag always holds this one URL.</strong> Changing the NFC mode or its content
    changes what this URL does — it never changes the URL itself, so the physical card
    never needs reprogramming.
  </div>

  {{-- ---------- Mode selector ---------- --}}
  <div class="section-sep">
    <label class="fld">NFC Mode</label>
    <form method="POST" action="{{ route('admin.cards.nfc.mode', $card->public_id) }}">
      @csrf @method('PATCH')
      <fieldset @disabled(! $editing)>
      <div class="modegrid">
        @foreach (\App\Enums\NfcMode::ordered() as $m)
          {{-- A hidden mode is hidden, not removed: no card can be switched
               INTO one from here, but a card already serving it keeps its
               option visible so the admin can see and change what it is. --}}
          @continue($m->isHidden() && $mode !== $m)
          <label class="modeopt {{ $mode === $m ? 'on' : '' }}">
            <input type="radio" name="nfc_mode" value="{{ $m->value }}" @checked($mode === $m)>
            <span class="mt">{{ $m->label() }}</span>
            <span class="md">{{ $m->description() }}</span>
          </label>
        @endforeach
      </div>
      <button class="btn btn-p" type="submit" style="margin-top:12px">Set mode</button>
      <span class="small muted" style="margin-left:8px">Switching modes keeps every mode’s saved data.</span>
      </fieldset>
    </form>
  </div>

  {{-- ================= LINKS MODE ================= --}}
  @if ($mode === $M::Links)
    <div class="section-sep">
      <label class="fld">Status</label>
      <p class="small" style="margin:0 0 4px">
        @if ($active->count() === 0)
          <span class="badge b-off">Not configured</span><span class="muted">&nbsp;Tapping shows the setup page.</span>
        @elseif ($active->count() === 1)
          <span class="badge b-ok">1 Link</span><span class="muted">&nbsp;Tapping redirects straight to it.</span>
        @else
          <span class="badge b-ok">{{ $active->count() }} Links</span><span class="muted">&nbsp;Tapping shows the multi-link page.</span>
        @endif
      </p>
      @if ($card->nfc_page_title || $card->nfc_page_subtitle)
        <p class="small muted" style="margin:10px 0 0">
          Landing page: <strong style="color:var(--text)">{{ $card->nfc_page_title }}</strong>@if ($card->nfc_page_subtitle) — {{ $card->nfc_page_subtitle }}@endif
        </p>
      @endif
    </div>

    <form method="POST" action="{{ route('admin.cards.nfc.links.save', $card->public_id) }}">
      @csrf @method('PATCH')
      <fieldset @disabled(! $editing)>
        @include('admin.partials.nfc-links', ['card' => $card, 'links' => $links, 'editing' => $editing])
        <div class="row" style="margin-top:16px">
          <button class="btn btn-p" type="submit">Save changes</button>
        </div>
      </fieldset>
    </form>
  @endif

  {{-- ================= PROFILE PAGE MODE ================= --}}
  @if ($mode === $M::Profile)
    <div class="section-sep">
      <label class="fld">Status</label>
      <p class="small" style="margin:0 0 14px">
        @if ($nfcProfile && $nfcProfile->isMeaningful())
          <span class="badge b-ok">Profile page live</span>
          <span class="muted">&nbsp;Tapping shows the profile page.</span>
          <a class="small" href="{{ route('nfc.show', $card->public_id) }}" target="_blank" rel="noopener" style="margin-left:8px">Preview</a>
        @else
          <span class="badge b-off">Needs a display name</span>
          <span class="muted">&nbsp;Tapping shows the setup page until one is set.</span>
        @endif
      </p>

      @if ($latestProfileEdit)
        <div style="padding:12px 14px;margin:0 0 16px;border:1px solid var(--warn-line);border-radius:8px;background:var(--warn-soft)">
          <p class="small" style="margin:0 0 9px">
            <strong>Client edit available to revert</strong><br>
            <span class="muted">{{ $latestProfileEdit->created_at->format('Y-m-d H:i') }} &middot; IP {{ $latestProfileEdit->ip_address }}</span>
          </p>
          <form method="POST" action="{{ route('admin.cards.profile.revert', $card->public_id) }}"
                onsubmit="return confirm('Revert the latest client profile edit? This restores the profile and links exactly as they were before that edit.')">
            @csrf
            <button class="btn btn-sm" type="submit">Revert latest client edit</button>
          </form>
        </div>
      @endif

      <form method="POST" action="{{ route('admin.cards.profile.update', $card->public_id) }}" enctype="multipart/form-data">
        @csrf @method('PATCH')
        <fieldset @disabled(! $editing)>

        <p class="fld" style="margin-top:4px">Identity</p>
        <div class="fgrid">
          @foreach ([
            // One made-up person across the whole system, admin side and
            // customer side: a real name in a placeholder reads as somebody's
            // actual card, and this one is nobody's.
            ['display_name','Name on the page',120,'e.g. John Doe'],
            ['headline','What they do',200,'e.g. Owner at Acme Inc.'],
            ['username','Username',60,'e.g. johndoe'],
            ['location','Where they are',100,'e.g. Manila'],
            ['tagline','Short line about them',200,'e.g. Always happy to connect'],
          ] as [$field,$label,$max,$placeholder])
            <label><span>{{ $label }}</span><input type="text" name="{{ $field }}" maxlength="{{ $max }}"
              placeholder="{{ $placeholder }}" value="{{ old($field, $nfcProfile?->$field) }}"></label>
          @endforeach
          {{-- Typed inputs, so a phone gets a phone keypad and an email gets
               checked by the browser before the server has to say so. --}}
          <label><span>Contact number <span class="muted">(optional)</span></span>
            <input type="tel" name="phone" maxlength="40" placeholder="+63 917 123 4567"
                   value="{{ old('phone', $nfcProfile?->phone) }}"></label>
          <label><span>Email <span class="muted">(optional)</span></span>
            <input type="email" name="email" maxlength="190" placeholder="john@example.com"
                   value="{{ old('email', $nfcProfile?->email) }}"></label>
        </div>

        <p class="fld">Avatar</p>
        @if ($nfcProfile?->avatar_path)
          <div class="row" style="margin-bottom:10px">
            <img src="{{ asset('storage/'.$nfcProfile->avatar_path) }}" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
            <button class="btn btn-sm btn-d" type="submit" form="del-profile-avatar">Remove avatar</button>
            @if ($nfcProfile->brand_color)<code>{{ $nfcProfile->brandColor() }}</code>@endif
          </div>
        @endif
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-crop>
        <p class="small muted" style="margin:7px 0 14px">Up to 5 MB. The page colour is taken from the photo.</p>

        {{-- Field names stay (welcome_eyebrow and friends are columns); the
             labels do not. "Eyebrow" is a typographer's word, and the people
             filling this in read it as the one above an eye. --}}
        <p class="fld">Welcome message, shown inside the Advanced details panel</p>
        <div class="fgrid">
          <label><span>Small line above the title</span><input type="text" name="welcome_eyebrow" maxlength="60" placeholder="e.g. Welcome" value="{{ old('welcome_eyebrow', $nfcProfile?->welcome_eyebrow) }}"></label>
          <label><span>Big title</span><input type="text" name="welcome_heading" maxlength="200" placeholder="e.g. Let&rsquo;s work together" value="{{ old('welcome_heading', $nfcProfile?->welcome_heading) }}"></label>
        </div>
        <label class="fld" for="welcome-body">Message</label>
        <textarea id="welcome-body" name="welcome_body" rows="5" maxlength="600">{{ old('welcome_body', $nfcProfile?->welcome_body) }}</textarea>
        <div class="fgrid" style="margin-top:12px">
          <label><span>Button text</span><input type="text" name="welcome_cta_label" maxlength="60" placeholder="e.g. Book a call" value="{{ old('welcome_cta_label', $nfcProfile?->welcome_cta_label) }}"></label>
          <label><span>Where the button goes</span><input type="url" name="welcome_cta_url" maxlength="500" placeholder="https://example.com" value="{{ old('welcome_cta_url', $nfcProfile?->welcome_cta_url) }}"></label>
        </div>

        {{-- The public profile page's Accounts panel is these same links, so
             they are edited here rather than only in Links mode: switching the
             mode back to reach them would change what the live URL serves
             mid-edit. They sit INSIDE this form because a profile page and its
             links are one thing to the person editing them, and were two
             saves only because they are two tables. --}}
        @include('admin.partials.nfc-links', ['card' => $card, 'links' => $links, 'editing' => $editing])

        <div class="row" style="margin-top:16px">
          <button class="btn btn-p" type="submit">Save changes</button>
          @if ($nfcProfile)
            <button class="btn btn-sm btn-d" type="submit" form="del-profile">Delete profile details</button>
          @endif
        </div>
        </fieldset>
      </form>
      @if ($nfcProfile)
        <form id="del-profile" method="POST" action="{{ route('admin.cards.profile.delete', $card->public_id) }}"
              onsubmit="return confirm('Delete the profile page for this card?\n\nCLEARED: every profile page field, and the uploaded image if there is one. Nothing of the profile is kept.\nKEPT: all NFC links, the QR destination, and every other mode\'s saved data.\n\nThis is permanent. Switching modes does not do this — only this button does.')">
          @csrf @method('DELETE')
        </form>
        <form id="del-profile-avatar" method="POST" action="{{ route('admin.cards.profile.avatar.delete', $card->public_id) }}"
              onsubmit="return confirm('Remove this profile avatar and its derived page colour? The rest of the profile stays unchanged.')">
          @csrf @method('DELETE')
        </form>
      @endif
    </div>

  @endif

  {{-- ================= CONTACT CARD MODE ================= --}}
  @if ($mode === $M::Contact)
    <div class="section-sep">
      <label class="fld">Status</label>
      <p class="small" style="margin:0 0 14px">
        @if ($profile && $profile->isMeaningful())
          <span class="badge b-ok">Contact card live</span>
          <a class="small" href="{{ route('nfc.vcard', $card->public_id) }}" style="margin-left:8px">Preview .vcf</a>
        @else
          <span class="badge b-off">Needs a name or company</span>
        @endif
      </p>

      <form method="POST" action="{{ route('admin.cards.contact.update', $card->public_id) }}" enctype="multipart/form-data">
        @csrf @method('PATCH')
        <fieldset @disabled(! $editing)>

        <p class="fld" style="margin-top:4px">Personal</p>
        <div class="fgrid">
          @foreach ([['first_name','First name'],['middle_name','Middle name'],['last_name','Last name'],['suffix','Suffix'],['job_title','Job title'],['company','Company / organisation']] as [$f,$lab])
            <label><span>{{ $lab }}</span><input type="text" name="{{ $f }}" value="{{ old($f, $profile?->$f) }}"></label>
          @endforeach
        </div>

        <p class="fld">Phone <span class="muted" style="font-weight:400">— digits and + ( ) - . only</span></p>
        <div class="fgrid">
          @foreach ([['mobile_phone','Mobile'],['work_phone','Work'],['landline','Landline'],['other_phone','Other']] as [$f,$lab])
            <label><span>{{ $lab }}</span><input type="tel" name="{{ $f }}" value="{{ old($f, $profile?->$f) }}"></label>
          @endforeach
        </div>

        <p class="fld">Email &amp; web</p>
        <div class="fgrid">
          <label><span>Personal email</span><input type="email" name="personal_email" value="{{ old('personal_email', $profile?->personal_email) }}"></label>
          <label><span>Work email</span><input type="email" name="work_email" value="{{ old('work_email', $profile?->work_email) }}"></label>
          <label class="wide"><span>Website</span><input type="url" name="website" placeholder="https://…" value="{{ old('website', $profile?->website) }}"></label>
        </div>

        <p class="fld">Address</p>
        <div class="fgrid">
          <label class="wide"><span>Street</span><input type="text" name="street" value="{{ old('street', $profile?->street) }}"></label>
          @foreach ([['city','City'],['state','Province / State'],['postal_code','Postal code'],['country','Country']] as [$f,$lab])
            <label><span>{{ $lab }}</span><input type="text" name="{{ $f }}" value="{{ old($f, $profile?->$f) }}"></label>
          @endforeach
        </div>

        <p class="fld">Other</p>
        <div class="fgrid">
          <label><span>Birthday</span><input type="date" name="birthday" value="{{ old('birthday', $profile?->birthday?->format('Y-m-d')) }}"></label>
          <label><span>Related name</span><input type="text" name="related_name" value="{{ old('related_name', $profile?->related_name) }}"></label>
          <label class="wide"><span>Notes</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $profile?->notes) }}</textarea></label>
        </div>

        <p class="fld">Social profiles</p>
        <div class="fgrid">
          @foreach ([['facebook_url','Facebook'],['instagram_url','Instagram'],['linkedin_url','LinkedIn'],['tiktok_url','TikTok'],['x_url','X / Twitter'],['youtube_url','YouTube'],['other_social_url','Other']] as [$f,$lab])
            <label><span>{{ $lab }}</span><input type="url" name="{{ $f }}" placeholder="https://…" value="{{ old($f, $profile?->$f) }}"></label>
          @endforeach
        </div>

        <p class="fld">Photo <span class="muted" style="font-weight:400">— JPEG/PNG/WebP, max 2 MB</span></p>
        <div class="row row-tight" style="margin-bottom:14px">
          @if ($profile?->photo_path)
            <img src="{{ asset('storage/'.$profile->photo_path) }}" alt=""
                 style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
          @endif
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
          @if ($profile?->photo_path)
            <button class="btn btn-sm btn-d" type="submit" form="del-photo">Remove photo</button>
          @endif
        </div>

        <div class="row row-tight">
          <button class="btn btn-p" type="submit">Save changes</button>
          @if ($profile)
            <button class="btn btn-sm btn-d" type="submit" form="del-contact">Delete contact details</button>
          @endif
        </div>
        </fieldset>
      </form>

      @if ($profile?->photo_path)
        <form id="del-photo" method="POST" action="{{ route('admin.cards.contact.photo.delete', $card->public_id) }}"
              onsubmit="return confirm('Remove the contact photo?')">@csrf @method('DELETE')</form>
      @endif
      @if ($profile)
        <form id="del-contact" method="POST" action="{{ route('admin.cards.contact.delete', $card->public_id) }}"
              onsubmit="return confirm('Delete ALL contact details for this card?\n\nThis is permanent. Switching modes does not do this — only this button does.')">
          @csrf @method('DELETE')
        </form>
      @endif
    </div>
  @endif

  {{-- ================= PHONE MODE ================= --}}
  @if ($mode === $M::Phone)
    <div class="section-sep">
      <label class="fld">Status</label>
      <p class="small" style="margin:0 0 14px">
        @if ($card->nfc_phone_number)
          <span class="badge b-ok">Call action live</span>
          <span class="muted">&nbsp;Tapping shows a Call Now button.</span>
        @else
          <span class="badge b-off">No number set</span>
        @endif
      </p>
      <form method="POST" action="{{ route('admin.cards.phone.update', $card->public_id) }}">
        @csrf @method('PATCH')
        <fieldset @disabled(! $editing)>
        <label class="fld" for="phonenum">Phone number</label>
        <div class="row row-tight">
          <input id="phonenum" type="tel" name="nfc_phone_number" placeholder="+63 917 123 4567"
                 value="{{ old('nfc_phone_number', $card->nfc_phone_number) }}" style="flex:1;min-width:200px" required>
          <button class="btn btn-p" type="submit">Save</button>
          @if ($card->nfc_phone_number)
            <button class="btn btn-d" type="submit" form="del-phone">Clear</button>
          @endif
        </div>
        <p class="small muted" style="margin:8px 0 0">Digits and <code>+ ( ) - .</code> only.</p>
        </fieldset>
      </form>
      @if ($card->nfc_phone_number)
        <form id="del-phone" method="POST" action="{{ route('admin.cards.phone.delete', $card->public_id) }}">@csrf @method('DELETE')</form>
      @endif
    </div>
  @endif

  {{-- ================= EMAIL MODE ================= --}}
  @if ($mode === $M::Email)
    <div class="section-sep">
      <label class="fld">Status</label>
      <p class="small" style="margin:0 0 14px">
        @if ($card->nfc_email_address)
          <span class="badge b-ok">Email action live</span>
          <span class="muted">&nbsp;Tapping shows a Send Email button.</span>
        @else
          <span class="badge b-off">No address set</span>
        @endif
      </p>
      <form method="POST" action="{{ route('admin.cards.email.update', $card->public_id) }}">
        @csrf @method('PATCH')
        <fieldset @disabled(! $editing)>
        <div class="fgrid">
          <label class="wide"><span>Email address</span>
            <input type="email" name="nfc_email_address" value="{{ old('nfc_email_address', $card->nfc_email_address) }}" required></label>
          <label class="wide"><span>Subject <span class="muted">(optional)</span></span>
            <input type="text" name="nfc_email_subject" maxlength="200" value="{{ old('nfc_email_subject', $card->nfc_email_subject) }}"></label>
          <label class="wide"><span>Message <span class="muted">(optional)</span></span>
            <textarea name="nfc_email_body" rows="3" maxlength="2000">{{ old('nfc_email_body', $card->nfc_email_body) }}</textarea></label>
        </div>
        <div class="row row-tight" style="margin-top:12px">
          <button class="btn btn-p" type="submit">Save</button>
          @if ($card->nfc_email_address)
            <button class="btn btn-d" type="submit" form="del-email">Clear</button>
          @endif
        </div>
        </fieldset>
      </form>
      @if ($card->nfc_email_address)
        <form id="del-email" method="POST" action="{{ route('admin.cards.email.delete', $card->public_id) }}">@csrf @method('DELETE')</form>
      @endif
    </div>
  @endif
</div>

{{-- ══════════════ ACTIVATION ══════════════ --}}
<div class="panel panel-tight">
  <div class="row" style="justify-content:space-between">
    <div class="row row-tight">
      <span class="small" style="font-weight:570">Card PIN</span>
      <code id="pinbox" style="letter-spacing:.24em;padding:4px 12px">&bull;&bull;&bull;&bull;&bull;&bull;</code>
      <button class="btn btn-sm" type="button" id="revealbtn"
              data-url="{{ route('admin.cards.pin.reveal', $card->public_id) }}">Reveal PIN</button>
      @if ($card->pinIsPersonalized())
        <form method="POST" action="{{ route('admin.cards.pin.reset', $card->public_id) }}"
              onsubmit="return confirm('Reset this card\'s PIN back to the universal activation PIN?\n\nThe owner\'s personal PIN stops working immediately. They will be asked to set a new personal PIN the next time they set up or unlock the card.')">
          @csrf
          <button class="btn btn-sm btn-d" type="submit">Reset PIN</button>
        </form>
      @endif
    </div>
    <span class="badge {{ $card->pinIsPersonalized() ? 'b-ok' : 'b-off' }}">
      {{ $card->pinIsPersonalized() ? 'Personalized '.$card->pin_personalized_at->format('Y-m-d') : 'Universal PIN' }}
    </span>
  </div>
  <p class="small muted" style="margin:10px 0 0">
    Cards ship with the universal PIN <code>{{ \App\Services\CardGenerationService::UNIVERSAL_PIN }}</code>, which the
    owner replaces during setup. Reset PIN puts the card back on the universal one.
  </p>
</div>

{{-- ══════════════ DANGER ZONE ══════════════ --}}
{{-- Only while editing. A card opened to be read should not have two buttons
     on it that destroy a live customer's page. --}}
@if ($editing)
<div class="panel danger-zone">
  <h2>Danger zone</h2>
  {{-- This copy is a promise the admin acts on when re-issuing a card, so it
       names everything CardResetService::resetEverything() actually clears. It
       listed only links and landing-page details for two increments after the
       service grew the contact profile, its photo, the profile row and the
       avatar.

       Both buttons are here rather than beside the fields they clear. Reset NFC
       used to sit in the shared link editor's "Add link" row, gated on the card
       having at least one link — so a Profile card with a finished profile and
       no links was offered nothing, and adding one link made a
       profile-destroying control appear next to "Add link". Neither action is
       scoped to a mode, so neither belongs inside one. --}}
  <p class="small" style="margin:0 0 14px;color:var(--text-soft)">
    <strong>Reset NFC</strong> clears everything the NFC URL can serve in every mode — all NFC links,
    the landing page details and its logo, the contact card and its photo, the profile page and its avatar,
    and any saved phone number or email action — and returns the card to Links mode.
    The QR destination survives. <strong>Reset entire card</strong> does all of that and clears the
    QR destination too.
    Both <strong>keep</strong> the card number, public ID, both permanent URLs and the activation PIN,
    so the physical card stays usable.
  </p>
  <div class="row row-tight">
    {{-- The dialog lists every mode, because resetNfcSide() clears every mode.
         It used to promise that "saved contact/phone/email data are NOT
         affected", which was true when the action only knew about links and
         false from the moment reset was centralised - an admin who read it and
         pressed the button lost the contact card it said would survive. --}}
    <form method="POST" action="{{ route('admin.cards.nfc.reset', $card->public_id) }}"
          onsubmit="return confirm('Reset the ENTIRE NFC side?\n\nCLEARED: all links, landing page details, contact card and photo, profile page and avatar, saved phone number, saved email action. The mode returns to Links.\nKEPT: the QR destination and both permanent URLs.\n\nThis cannot be undone.')">
      @csrf @method('DELETE')
      <button class="btn btn-d" type="submit">Reset NFC</button>
    </form>
    <form method="POST" action="{{ route('admin.cards.reset', $card->public_id) }}"
          onsubmit="return confirm('Reset the ENTIRE card?\n\nCLEARED: QR destination, all NFC links, landing page details, contact card and photo, profile page and avatar, saved phone number, saved email action. The mode returns to Links.\nKEPT: card number, public ID, both permanent URLs, activation PIN.\n\nThis cannot be undone.')">
      @csrf @method('DELETE')
      <button class="btn btn-d" type="submit">Reset entire card</button>
    </form>
  </div>
</div>
@endif

@include('partials.photo-cropper')

<style>
/* ── QR preview sizing ─────────────────────────────────────────
   Small enough to sit comfortably on screen and be scanned from a
   second phone. Never exceeds its container. Download quality is
   completely unaffected by this. */
.qr-layout{display:flex;gap:26px;flex-wrap:wrap;align-items:flex-start}
.qr-preview{margin:0;flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:8px}
.qr-plate{
  width:min(240px, 70vw);
  aspect-ratio:1/1;                 /* keeps it square regardless of content */
  background:#fff;
  border:1px solid var(--line);
  box-shadow:var(--shadow-sm);
  display:flex;align-items:center;justify-content:center;
}
.qr-plate svg{
  width:100%;height:100%;display:block;
  image-rendering:pixelated;        /* crisp module edges when scaled down */
  shape-rendering:crispEdges;
}
.qr-preview figcaption{text-align:center;max-width:min(240px,70vw)}
.qr-detail{flex:1;min-width:280px}

/* ── NFC mode selector ─────────────────────────────────────── */
.modegrid{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(215px,1fr))}
.modeopt{
  display:block;border:1px solid var(--line-strong);border-radius:9px;padding:11px 13px;
  cursor:pointer;background:var(--surface);transition:border-color .12s ease,background .12s ease;
}
.modeopt:hover{border-color:var(--muted)}
.modeopt.on{border-color:var(--accent);background:var(--accent-soft)}
.modeopt input{margin-right:7px;vertical-align:middle;width:auto}
.modeopt .mt{font-weight:570;font-size:.88rem}
.modeopt .md{display:block;color:var(--muted);font-size:.78rem;margin-top:3px;padding-left:21px}

@media (max-width:720px){
  .qr-layout{gap:20px}
  .qr-preview{width:100%;align-items:center}
  .qr-detail{min-width:0;width:100%}
}
</style>

<script>
function copyText(id, btn){
  var t = document.getElementById(id).textContent.trim();
  var done = function(){ var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function(){ btn.textContent = o; }, 1400); };
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done, function(){}); }
  else { var a=document.createElement('textarea'); a.value=t; document.body.appendChild(a); a.select();
         try { document.execCommand('copy'); done(); } catch(e){} document.body.removeChild(a); }
}
document.getElementById('revealbtn').addEventListener('click', function(){
  var btn = this, box = document.getElementById('pinbox');
  if (btn.dataset.shown === '1') {
    box.textContent = '••••••'; btn.textContent = 'Reveal PIN'; btn.dataset.shown = '0'; return;
  }
  fetch(btn.dataset.url, {
    method: 'POST',
    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json'},
    credentials: 'same-origin'
  })
  .then(function(r){ if(!r.ok) throw new Error(r.status); return r.json(); })
  .then(function(d){ box.textContent = d.pin; btn.textContent = 'Hide PIN'; btn.dataset.shown = '1'; })
  .catch(function(){ box.textContent = 'error'; });
});
</script>
@endsection

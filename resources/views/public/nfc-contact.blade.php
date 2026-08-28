<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
@include('partials.exo2')
<title>{{ $profile->displayName() ?: $profile->company }}</title>
<style>
/* Digital business card. Light, mobile-first, no JavaScript, no admin surface. */
/* The ViteSEO palette. The teal is the fill of the primary action and
   --on-accent the ink on it; --accent-ink is the teal that can be text. */
:root{
  --bg:#fafafe; --surface:#ffffff; --surface-hover:#f1f2f5;
  --text:#111827; --text-soft:#374151; --muted:#6b7280;
  --line:#e5e7ea; --line-strong:#d3d7de;
  --accent:#4fdbd7; --on-accent:#19414b; --accent-ink:#0e7d79; --accent-press:#3ccfcb;
  --radius:14px;
}
*{box-sizing:border-box}
body{
  margin:0;background:var(--bg);color:var(--text);
  font:1rem/1.5 "Exo 2",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  padding:32px 18px calc(40px + env(safe-area-inset-bottom));
}
.wrap{max-width:420px;margin:0 auto}
header{text-align:center;margin-bottom:22px}
.photo{width:96px;height:96px;border-radius:50%;object-fit:cover;margin:0 auto 14px;
 display:block;border:1px solid var(--line);background:var(--surface)}
h1{font-size:1.6875rem;line-height:1.2;font-weight:700;margin:0;letter-spacing:-.02em;overflow-wrap:anywhere}
.title{color:var(--text-soft);margin:5px 0 0;font-size:1rem;overflow-wrap:anywhere}
.org{color:var(--muted);margin:2px 0 0;font-size:.9375rem;overflow-wrap:anywhere}

/* Primary actions sit above the fold on a phone. */
.actions{display:flex;flex-direction:column;gap:9px;margin-bottom:22px}
.act{
  display:flex;align-items:center;justify-content:center;min-height:52px;
  padding:14px 18px;border-radius:12px;text-decoration:none;font-weight:600;font-size:1rem;
  border:1px solid var(--line-strong);background:var(--surface);color:var(--text);
  box-shadow:0 1px 2px rgba(16,24,40,.04);
}
.act:hover{background:var(--surface-hover)}
.act:active{transform:scale(.99)}
.act-primary{background:var(--accent);border-color:var(--accent);color:var(--on-accent)}
.act-primary:hover{background:var(--accent-press)}
.act-row{display:flex;gap:9px}
.act-row .act{flex:1}

.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
 padding:4px 16px;margin-bottom:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.field{display:flex;justify-content:space-between;gap:14px;padding:12px 0;
 border-bottom:1px solid var(--line);align-items:flex-start}
.panel .field:last-child{border-bottom:0}
.field .k{color:var(--muted);font-size:.8125rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;flex:0 0 auto;padding-top:2px}
.field .v{text-align:right;overflow-wrap:anywhere;font-size:.9375rem}
.field .v a{color:var(--accent-ink);text-decoration:none}
.field .v a:hover{text-decoration:underline}
.sec{font-size:.8125rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:20px 4px 8px}
.note{white-space:pre-wrap;overflow-wrap:anywhere;font-size:.92rem;color:var(--text-soft);padding:12px 0}
.hint{text-align:center;color:var(--muted);font-size:.78rem;margin:16px 6px 0;line-height:1.45}
footer{text-align:center;color:var(--muted);font-size:.8125rem;margin-top:22px}
footer .own{color:var(--muted);text-decoration:none;display:inline-block;margin-bottom:7px}
footer .own:hover{color:var(--text);text-decoration:underline}
@media (prefers-reduced-motion:reduce){.act{transition:none}}
</style>
</head>
<body>
<main class="wrap">
  <header>
    @if ($profile->photo_path)
      <img class="photo" src="{{ asset('storage/'.$profile->photo_path) }}" alt="">
    @endif
    <h1>{{ $profile->displayName() ?: $profile->company }}</h1>
    @if ($profile->job_title)<p class="title">{{ $profile->job_title }}</p>@endif
    @if ($profile->company && $profile->displayName())<p class="org">{{ $profile->company }}</p>@endif
  </header>

  <div class="actions">
    <a class="act act-primary" href="{{ route('nfc.vcard', $card->public_id) }}">Save to Contacts</a>

    @php
      $phone = $profile->primaryPhone();
      $email = $profile->primaryEmail();
    @endphp
    @if ($phone || $email)
      <div class="act-row">
        @if ($phone)
          <a class="act" href="tel:{{ \App\Rules\SafePhoneNumber::toDialable($phone) }}">Call</a>
        @endif
        @if ($email)
          <a class="act" href="mailto:{{ $email }}">Email</a>
        @endif
      </div>
    @endif

    @if ($profile->website)
      <a class="act" href="{{ $profile->website }}" rel="noopener noreferrer nofollow ugc" target="_blank">Website</a>
    @endif
  </div>

  @php $phones = $profile->phones(); $emails = $profile->emails(); @endphp

  @if ($phones || $emails)
    <div class="panel">
      @foreach ($phones as $label => $number)
        <div class="field">
          <span class="k">{{ $label }}</span>
          <span class="v"><a href="tel:{{ \App\Rules\SafePhoneNumber::toDialable($number) }}">{{ $number }}</a></span>
        </div>
      @endforeach
      @foreach ($emails as $label => $address)
        <div class="field">
          <span class="k">{{ $label }} email</span>
          <span class="v"><a href="mailto:{{ $address }}">{{ $address }}</a></span>
        </div>
      @endforeach
    </div>
  @endif

  @if ($profile->hasAddress() || $profile->birthday || $profile->related_name)
    <div class="panel">
      @if ($profile->hasAddress())
        <div class="field"><span class="k">Address</span><span class="v">{{ $profile->addressLine() }}</span></div>
      @endif
      @if ($profile->birthday)
        <div class="field"><span class="k">Birthday</span><span class="v">{{ $profile->birthday->format('j F Y') }}</span></div>
      @endif
      @if ($profile->related_name)
        <div class="field"><span class="k">Related</span><span class="v">{{ $profile->related_name }}</span></div>
      @endif
    </div>
  @endif

  @php $socials = $profile->socials(); @endphp
  @if ($socials)
    <p class="sec">Social profiles</p>
    <div class="panel">
      @foreach ($socials as $label => $url)
        <div class="field">
          <span class="k">{{ $label }}</span>
          <span class="v"><a href="{{ $url }}" rel="noopener noreferrer nofollow ugc" target="_blank">Open</a></span>
        </div>
      @endforeach
    </div>
  @endif

  @if ($profile->notes)
    <p class="sec">Notes</p>
    <div class="panel"><div class="note">{{ $profile->notes }}</div></div>
  @endif

  <p class="hint">
    Tapping “Save to Contacts” downloads a contact file. Your phone will ask you to
    confirm before anything is added to your address book.
  </p>

  <footer>
    <a class="own" href="{{ route('nfc.manage', $card->public_id) }}">Card owner? Manage this card</a>
    <div>Powered by ViteSEO</div>
  </footer>
</main>
</body>
</html>

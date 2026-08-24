<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title>{{ $profile->display_name }}</title>
{{-- The brand colour is derived from the avatar and normalised into a dark
     band, so --on-brand can be a fixed light value with no luminance branch.
     brandColor() guarantees a hex value; nothing else may be interpolated
     into this attribute. --}}
<style>
:root{
  --brand:{{ $profile->brandColor() }};
  --brand-soft:{{ $profile->brandColorSoftRgba() }};
  /* Nothing on the page consumes --on-brand yet. Both of its users sit on the
     brand-coloured background outside the white cards: the overflow menu
     trigger and the share control in increment 3, and the footer nav in
     increment 4. Kept rather than dropped so the pair stays together -
     --on-brand-dim below is already in use by the byline, and re-deriving one
     half of a contrast pair from the mockup later is how the two drift. */
  --on-brand:#e8f1ec;
  --on-brand-dim:#a9c2b6;
  --card:#fff;
  --text:#101418;
  --soft:#3f4854;
  --muted:#6b7480;
  --line:#e6e8eb;
  --pill:#e9edf0;
  --radius:20px;
}
*{box-sizing:border-box}
body{
  margin:0;background:var(--brand);color:var(--text);
  font:17px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  padding:26px 20px calc(38px + env(safe-area-inset-bottom));
}
.wrap{max-width:680px;margin:0 auto}

.card{background:var(--card);border-radius:var(--radius);overflow:hidden;
 border:1px solid rgba(0,0,0,.06);margin-bottom:18px}
/* Cover is the brand colour at low alpha - same hue, lighter. */
.cover{height:150px;background:var(--brand-soft)}
.idblock{padding:0 28px 26px}
.avatar-row{margin-top:-54px;margin-bottom:16px}
.avatar-slot{position:relative;width:110px;height:110px}
.avatar-fallback{
  width:110px;height:110px;border-radius:50%;border:4px solid var(--card);
  display:grid;place-items:center;font-weight:640;font-size:2.1rem;color:#fff;
  background:var(--brand);
}
.avatar-img{width:110px;height:110px;border-radius:50%;border:4px solid var(--card);
 object-fit:cover;display:block}

h1{font-size:1.95rem;font-weight:680;margin:0;letter-spacing:-.024em;overflow-wrap:anywhere}
.headline{color:var(--soft);margin:7px 0 0;font-size:1.02rem;overflow-wrap:anywhere}
.meta{color:var(--muted);margin:4px 0 0;font-size:.94rem}

.iconrow{display:flex;align-items:center;gap:17px;margin:18px 0 0;flex-wrap:wrap}
.iconrow a{color:#20262e;display:grid;place-items:center}
.iconrow svg{width:19px;height:19px;fill:currentColor;display:block}

.tagline{margin:20px 0 0;font-size:1.02rem;color:var(--soft);overflow-wrap:anywhere}

.card-pad{padding:24px 28px}
h2{font-size:1.14rem;font-weight:650;margin:0 0 6px;letter-spacing:-.012em}
.acct{display:flex;gap:15px;align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--line)}
.acct:last-child{border-bottom:0}
.acct-ico{flex:0 0 auto;width:21px;padding-top:2px;color:#20262e}
.acct-ico svg{width:21px;height:21px;fill:currentColor;display:block}
.acct-name{font-size:1rem;font-weight:610}
.acct-url{font-size:.9rem;color:var(--muted);overflow-wrap:anywhere;text-decoration:none;display:block;margin-top:2px}
.acct-url:hover{text-decoration:underline}
/* A destination that cannot be followed. Struck through and never underlined
   on hover, so it does not read as a link that simply failed to respond. */
.acct-dead{font-size:.9rem;color:var(--muted);overflow-wrap:anywhere;display:block;margin-top:2px;
 text-decoration:line-through}

details.adv{margin:6px 0 0;text-align:center}
details.adv>summary{
  list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;
  background:var(--pill);color:#2b333c;border-radius:999px;padding:11px 19px;
  font-size:.92rem;font-weight:550;
}
details.adv>summary::-webkit-details-marker{display:none}
.adv-body{background:var(--card);border:1px solid rgba(0,0,0,.06);border-radius:16px;
 margin-top:14px;padding:6px 24px;text-align:left}
.kv{display:flex;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--line);font-size:.94rem}
.kv:last-child{border-bottom:0}
.kv .k{color:var(--muted)}
.kv .v{text-align:right;overflow-wrap:anywhere}

footer{margin-top:32px;text-align:center}
.by{color:var(--on-brand-dim);font-size:.78rem}
</style>
</head>
<body>
<div class="wrap">

  <section class="card">
    <div class="cover"></div>
    <div class="idblock">

      <div class="avatar-row">
        <div class="avatar-slot">
          @if ($profile->avatar_path)
            <img class="avatar-img" src="{{ asset('storage/'.$profile->avatar_path) }}" alt="">
          @else
            <div class="avatar-fallback">{{ $profile->initials() }}</div>
          @endif
        </div>
      </div>

      <h1>{{ $profile->display_name }}</h1>
      @if ($profile->headline)<p class="headline">{{ $profile->headline }}</p>@endif
      @if ($profile->username || $profile->location)
        <p class="meta">{{ trim(implode(' · ', array_filter([$profile->username, $profile->location]))) }}</p>
      @endif

      {{-- Brand links only. A row of six identical globes is noise rather than
           navigation, so unrecognised hosts appear in the panel below instead.

           This same filter is what keeps a defused link out of the row, and
           deliberately so: LinkPlatform::forUrl() returns 'web' for anything
           safeHref() would defuse, and the row carries no 'web' links. A bare
           mark has no text to explain that it leads nowhere, so the row has no
           dead state and needs none. The two rules are coupled;
           test_the_icon_row_never_carries_a_defused_link pins the consequence
           so a change to either is caught rather than shipping a dead mark. --}}
      @php
        $branded = $links->filter(fn ($l) => \App\Services\LinkPlatform::forUrl($l->url) !== 'web');
      @endphp
      @if ($branded->isNotEmpty())
        <div class="iconrow">
          @foreach ($branded as $link)
            @php $platform = \App\Services\LinkPlatform::forUrl($link->url); @endphp
            <a href="{{ \App\Services\LinkPlatform::safeHref($link->url) }}" rel="noopener noreferrer nofollow ugc" target="_blank"
               aria-label="{{ \App\Services\LinkPlatform::label($platform) }}">
              <x-platform-icon :platform="$platform" />
            </a>
          @endforeach
        </div>
      @endif

      @if ($profile->tagline)<p class="tagline">{{ $profile->tagline }}</p>@endif
    </div>
  </section>

  @if ($links->isNotEmpty())
    <section class="card card-pad">
      <h2>Accounts</h2>
      {{-- No verification badges anywhere: nothing here proves account
           ownership, and a checkmark would assert a guarantee that does not
           exist. Hence the plain heading rather than a verified-profile framing. --}}
      @foreach ($links as $link)
        @php
          $platform = \App\Services\LinkPlatform::forUrl($link->url);
          $href     = \App\Services\LinkPlatform::safeHref($link->url);
          $display  = \App\Services\LinkPlatform::displayUrl($link->url);
        @endphp
        <div class="acct">
          <span class="acct-ico"><x-platform-icon :platform="$platform" /></span>
          <div>
            <div class="acct-name">{{ \App\Services\LinkPlatform::label($platform) }}</div>
            @if ($href === '#')
              {{-- Defused, so not an anchor at all. href="#" resolves to this
                   page, which with target="_blank" would open a second copy of
                   the profile and read as a link that worked. The row is kept
                   rather than dropped — silently discarding an administrator's
                   row hides the problem instead of showing it. --}}
              <span class="acct-dead">{{ $display }}</span>
            @else
              <a class="acct-url" href="{{ $href }}" rel="noopener noreferrer nofollow ugc" target="_blank">{{ $display }}</a>
            @endif
          </div>
        </div>
      @endforeach
    </section>
  @endif

  <details class="adv">
    {{-- The summary is inline-flex with an 8px gap, which is a two-child
         layout: the chevron is what the gap spaces and what makes the pill read
         as a control rather than a stray word. Copied from the mockup verbatim
         rather than retyped - mistyped path data renders as a smear. --}}
    <summary>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6 3 12l5 6"/><path d="m16 6 5 6-5 6"/></svg>
      Advanced details
    </summary>
    <div class="adv-body">
      {{-- The permanent URL, never the card number: publishing a sequential
           internal id tells a visitor how many cards exist. --}}
      <div class="kv"><span class="k">Permanent URL</span><span class="v">{{ $card->nfcUrl() }}</span></div>
      <div class="kv"><span class="k">Last updated</span><span class="v">{{ $profile->updated_at->format('j F Y') }}</span></div>
    </div>
  </details>

  <footer>
    {{-- The three global page links land in increment 4 with site_pages. --}}
    <div class="by">Powered by ViteSEO</div>
  </footer>

</div>
</body>
</html>

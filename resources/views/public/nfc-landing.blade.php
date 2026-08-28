<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
@include('partials.exo2')
<title>{{ $card->nfc_page_title ?: 'Links' }}</title>
<style>
/* Minimalist multi-link page. Light-first, mobile-first, no JavaScript. */
/* The ViteSEO palette, same values as the platform. */
:root{
  --bg:#fafafe; --surface:#ffffff; --surface-hover:#f1f2f5;
  --text:#111827; --muted:#6b7280; --line:#e5e7ea; --line-strong:#d3d7de;
  --accent:#4fdbd7;
  --radius:14px;
}
*{box-sizing:border-box}
body{
  margin:0; background:var(--bg); color:var(--text);
  font:1rem/1.5 "Exo 2",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  min-height:100dvh; display:flex; align-items:center; justify-content:center;
  padding:44px 20px calc(44px + env(safe-area-inset-bottom));
}
.wrap{width:100%;max-width:410px}
header{text-align:center;margin-bottom:28px}
.logo{width:78px;height:78px;border-radius:20px;object-fit:cover;margin:0 auto 14px;
 display:block;border:1px solid var(--line);background:var(--surface)}
h1{font-size:1.6875rem;line-height:1.2;font-weight:700;margin:0;letter-spacing:-.02em;overflow-wrap:anywhere}
p.tag{color:var(--muted);margin:7px 0 0;font-size:.9375rem;overflow-wrap:anywhere}
a.link{
  display:flex;align-items:center;justify-content:center;text-align:center;gap:10px;
  min-height:56px;padding:15px 20px;margin-bottom:11px;
  background:var(--surface);color:var(--text);text-decoration:none;
  border:1px solid var(--line-strong);border-radius:var(--radius);
  font-weight:600;font-size:1rem;overflow-wrap:anywhere;
  box-shadow:0 1px 2px rgba(16,24,40,.04);
  transition:transform .12s ease,background .12s ease,border-color .12s ease;
}
a.link .ic{display:inline-flex;flex:0 0 auto;color:#20262e}
a.link .ic svg{width:19px;height:19px;fill:currentColor;display:block}
/* An outlined mark declares fill="none" and draws with its stroke; the
   blanket fill above would turn the globe and the Instagram square into
   solid blobs, so it is handed back to the attribute. */
a.link .ic svg[fill="none"]{fill:none}
a.link:hover{background:var(--surface-hover);border-color:var(--accent)}
a.link:active{transform:scale(.99)}
a.link:focus-visible{outline:2px solid var(--text);outline-offset:2px}
footer{text-align:center;color:var(--muted);font-size:.8125rem;margin-top:26px}
footer .own{color:var(--muted);text-decoration:none;display:inline-block;margin-bottom:7px}
footer .own:hover{color:var(--text);text-decoration:underline}
@media (prefers-reduced-motion:reduce){a.link{transition:none}}
</style>
</head>
<body>
<main class="wrap">
  {{-- Both branding fields are optional and most partners leave them blank, so
       omit the header entirely rather than emitting an empty one that still
       carries its bottom margin. --}}
  @if ($card->nfc_logo_path || $card->nfc_page_title || $card->nfc_page_subtitle)
    <header>
      @if ($card->nfc_logo_path)
        <img class="logo" src="{{ asset('storage/'.$card->nfc_logo_path) }}" alt="">
      @endif
      @if ($card->nfc_page_title)
        <h1>{{ $card->nfc_page_title }}</h1>
      @endif
      @if ($card->nfc_page_subtitle)
        <p class="tag">{{ $card->nfc_page_subtitle }}</p>
      @endif
    </header>
  @endif

  <nav>
    @foreach ($links as $link)
      @php $icon = \App\Services\LinkPlatform::iconFor($link->icon, $link->url); @endphp
      <a class="link" href="{{ $link->url }}" rel="noopener noreferrer nofollow ugc" target="_blank">
        @if ($icon !== \App\Services\LinkPlatform::NONE)
          <span class="ic" aria-hidden="true"><x-platform-icon :platform="$icon" /></span>
        @endif
        <span class="lt">{{ $link->title }}</span>
      </a>
    @endforeach
  </nav>

  <footer>
    <a class="own" href="{{ route('nfc.manage', $card->public_id) }}">Card owner? Manage this card</a>
    <div>Powered by ViteSEO</div>
  </footer>
</main>
</body>
</html>

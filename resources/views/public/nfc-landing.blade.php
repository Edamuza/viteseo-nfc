<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title>{{ $card->nfc_page_title ?: 'Links' }}</title>
<style>
/* Minimalist multi-link page. Light-first, mobile-first, no JavaScript. */
:root{
  --bg:#f6f7f9; --surface:#fff; --surface-hover:#f3f4f6;
  --text:#16191d; --muted:#6b7280; --line:#e5e7eb; --line-strong:#d7dbe0;
  --radius:14px;
}
*{box-sizing:border-box}
body{
  margin:0; background:var(--bg); color:var(--text);
  font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  min-height:100dvh; display:flex; align-items:center; justify-content:center;
  padding:44px 20px calc(44px + env(safe-area-inset-bottom));
}
.wrap{width:100%;max-width:410px}
header{text-align:center;margin-bottom:28px}
.logo{width:78px;height:78px;border-radius:20px;object-fit:cover;margin:0 auto 14px;
 display:block;border:1px solid var(--line);background:var(--surface)}
h1{font-size:1.4rem;font-weight:650;margin:0;letter-spacing:-.02em;overflow-wrap:anywhere}
p.tag{color:var(--muted);margin:7px 0 0;font-size:.93rem;overflow-wrap:anywhere}
a.link{
  display:flex;align-items:center;justify-content:center;text-align:center;
  min-height:56px;padding:15px 20px;margin-bottom:11px;
  background:var(--surface);color:var(--text);text-decoration:none;
  border:1px solid var(--line-strong);border-radius:var(--radius);
  font-weight:550;font-size:1rem;overflow-wrap:anywhere;
  box-shadow:0 1px 2px rgba(16,24,40,.04);
  transition:transform .12s ease,background .12s ease,border-color .12s ease;
}
a.link:hover{background:var(--surface-hover);border-color:var(--muted)}
a.link:active{transform:scale(.99)}
a.link:focus-visible{outline:2px solid var(--text);outline-offset:2px}
footer{text-align:center;color:var(--muted);font-size:.73rem;margin-top:26px}
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
      <a class="link" href="{{ $link->url }}" rel="noopener noreferrer nofollow ugc" target="_blank">{{ $link->title }}</a>
    @endforeach
  </nav>

  <footer>Powered by ViteSEO</footer>
</main>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
{{-- Card pages stay noindex: they are personal setup/redirect endpoints and
     thin duplicates in search. Only the home page overrides this. --}}
<meta name="robots" content="@yield('robots', 'noindex, nofollow')">
<meta name="color-scheme" content="light">
<title>@yield('title', 'Card Setup')</title>
@yield('head')
<style>
/* Public setup pages: light, mobile-first, deliberately plain.
   No admin navigation or admin actions are ever rendered here. */
:root{
  --bg:#f7f8fa; --surface:#fff; --text:#16191d; --text-soft:#4a5260;
  --muted:#6b7280; --line:#e5e7eb; --line-strong:#d3d8df;
  --accent:#2563eb; --accent-soft:#eff4ff; --accent-press:#1d4ed8;
  --danger:#b42318; --danger-soft:#fef3f2; --danger-line:#fecdca;
  --radius:12px;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--bg); color:var(--text);
  font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
  padding:32px 16px calc(48px + env(safe-area-inset-bottom));
}
.wrap{max-width:430px;margin:0 auto}
.card{
  background:var(--surface); border:1px solid var(--line); border-radius:var(--radius);
  padding:26px 22px; box-shadow:0 1px 3px rgba(16,24,40,.06);
}
h1{font-size:1.2rem;font-weight:640;margin:0 0 6px;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:.9rem;margin:0 0 20px;line-height:1.5}
label{display:block;font-weight:570;font-size:.85rem;margin:16px 0 6px;color:var(--text-soft)}
label .opt{font-weight:400;color:var(--muted)}
input[type=text],input[type=url],input[type=tel],input[type=password]{
  width:100%;padding:12px 13px;font-size:1rem;border:1px solid var(--line-strong);
  border-radius:9px;background:var(--surface);color:var(--text);font-family:inherit;
}
input::placeholder{color:#9aa1ac}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pin{letter-spacing:.34em;text-align:center;font-size:1.15rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
button.primary,a.primary{
  display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;
  width:100%;margin-top:22px;padding:13px;font-size:.98rem;font-weight:580;
  border:0;border-radius:9px;background:var(--accent);color:#fff;cursor:pointer;
  min-height:46px;font-family:inherit;text-decoration:none;
}
button.primary:hover,a.primary:hover{background:var(--accent-press)}
button.primary:focus-visible,a.primary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.ghost{
  width:100%;margin-top:10px;padding:10px;font-size:.88rem;font-weight:550;
  background:transparent;color:var(--accent);border:1px dashed var(--line-strong);
  border-radius:9px;cursor:pointer;font-family:inherit;
}
.ghost:hover{border-color:var(--accent);background:var(--accent-soft)}
.err{
  background:var(--danger-soft);border:1px solid var(--danger-line);color:var(--danger);
  padding:11px 13px;border-radius:9px;margin-bottom:18px;font-size:.87rem;
}
.err ul{margin:0;padding-left:18px}
.foot{text-align:center;color:var(--muted);font-size:.76rem;margin-top:20px}
.linkrow{display:flex;gap:8px;margin-bottom:9px}
.linkrow .t{flex:0 0 38%}
.linkrow input{flex:1;min-width:0}
.ok{font-size:2.2rem;text-align:center;margin:2px 0 10px;color:#047857}
code{background:#f9fafb;border:1px solid var(--line);padding:2px 7px;border-radius:5px;
 font-size:.85em;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
</style>
</head>
<body>
<div class="wrap">
  @yield('body')
  <p class="foot">Secured by ViteSEO</p>
</div>
</body>
</html>

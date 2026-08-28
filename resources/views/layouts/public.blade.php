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
a{color:var(--accent);text-decoration:none;font-weight:550}
a:hover{text-decoration:underline}
a:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
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
input[type=text],input[type=url],input[type=tel],input[type=password],select{
  width:100%;padding:12px 13px;font-size:1rem;border:1px solid var(--line-strong);
  border-radius:9px;background:var(--surface);color:var(--text);font-family:inherit;
}
input::placeholder{color:#9aa1ac}
input:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
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
.cardid{text-align:center;color:var(--muted);font-size:.78rem;margin:16px 0 0;line-height:1.5}
.cardid code{font-size:.95em}
.cardid-hint{display:block}
.pin-hint{color:var(--muted);font-size:.8rem;margin:8px 0 0;line-height:1.45}
/* One link = one grouped card: label + URL (+ remove) on the first line, the
   logo dropdown on its own line below. Loose bare fields read as separate
   questions; the border is what makes the three of them one link. */
.linkrow{
  display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;
  border:1px solid var(--line);border-radius:11px;padding:11px;
  background:var(--surface);
}
.linkrow .t{flex:1 1 32%;min-width:120px}
.linkrow input{flex:1 1 52%;min-width:0}
.linkrow select{flex:1 1 100%;font-size:.9rem;padding:9px 11px;color:var(--text-soft)}
@media (max-width:560px){
  /* Narrow phones: the label takes the whole first line so the URL is never
     squeezed beside it. */
  .linkrow .t{flex-basis:100%}
}
.ok{font-size:2.2rem;text-align:center;margin:2px 0 10px;color:#047857}
code{background:#f9fafb;border:1px solid var(--line);padding:2px 7px;border-radius:5px;
 font-size:.85em;word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}

/* ---------- Setup progress ----------
   Three steps, one question per screen. Owners were abandoning a single page
   that asked for a PIN, a new PIN and every detail at once; the bar is what
   tells them how much is left. */
.steps{display:flex;gap:7px;list-style:none;margin:0 0 20px;padding:0;
 font-size:.71rem;font-weight:600;color:var(--muted)}
.steps li{flex:1;display:flex;flex-direction:column;gap:6px;line-height:1.3}
.steps .bar{height:4px;border-radius:999px;background:var(--line)}
.steps .done .bar,.steps .now .bar{background:var(--accent)}
.steps .now{color:var(--accent)}

/* ---------- Card-type choices (chooser + manage page) ---------- */
.choice{
  display:flex;align-items:stretch;margin-top:10px;overflow:hidden;
  border:1px solid var(--line-strong);border-radius:12px;background:var(--surface);
  transition:border-color .12s ease;
}
.choice:hover,.choice.now{border-color:var(--accent)}
.choice-go{
  flex:1;min-width:0;display:flex;align-items:center;gap:12px;padding:15px;
  color:var(--text);text-decoration:none;font-weight:500;
}
.choice-go:hover{text-decoration:none;background:var(--accent-soft)}
.choice .ct{display:block;font-weight:600;font-size:.97rem;margin-bottom:2px}
.choice .cd{display:block;color:var(--muted);font-size:.83rem;line-height:1.4;font-weight:400}
.choice .arrow{margin-left:auto;color:var(--muted);flex:0 0 auto}
.now-badge{
  margin-left:auto;flex:0 0 auto;font-size:.72rem;font-weight:600;color:var(--accent);
  background:var(--accent-soft);border:1px solid var(--accent);border-radius:999px;padding:3px 10px;
}
/* The eye sits BESIDE the link, never inside it: an <a> may not contain a
   <button>, and the two do different things - one previews, one commits. */
.eye{
  flex:0 0 auto;width:66px;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:3px;border:0;border-left:1px solid var(--line);
  background:var(--surface);color:var(--accent);cursor:pointer;
  font:inherit;font-size:.63rem;font-weight:600;
}
.eye:hover{background:var(--accent-soft)}
.eye:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.eye svg{width:19px;height:19px}

/* ---------- Phone preview ----------
   A mock of the tapped page, not the page itself: it has to redraw on every
   keystroke, before anything is saved, so it is drawn from the same field
   values the form is about to submit. */
.pv-modal{border:0;background:transparent;padding:0;max-width:94vw;overflow:visible}
.pv-modal::backdrop{background:rgba(16,24,40,.6)}
.pv-box{background:var(--surface);border-radius:16px;padding:16px;box-shadow:0 20px 50px rgba(16,24,40,.3)}
.pv-title{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:0 0 13px;font-size:.9rem;font-weight:640}
.pv-close{border:1px solid var(--line-strong);background:var(--surface);border-radius:8px;
 padding:6px 11px;font:inherit;font-size:.8rem;cursor:pointer;color:var(--text-soft)}
.pv-close:hover{background:var(--accent-soft);border-color:var(--accent)}
.pv-note{color:var(--muted);font-size:.76rem;text-align:center;margin:11px 0 0;line-height:1.45}
.pv-phone{
  width:250px;max-width:100%;margin:0 auto;overflow:hidden;background:#f6f7f9;
  border:8px solid #1f2430;border-radius:28px;box-shadow:0 6px 18px rgba(16,24,40,.18);
}
.pv-bezel{height:17px;background:#1f2430;position:relative}
.pv-bezel::after{content:"";position:absolute;left:50%;top:5px;transform:translateX(-50%);
 width:44px;height:5px;border-radius:999px;background:#39404e}
.pv-screen{height:355px;overflow-y:auto;padding:14px 12px;background:#f6f7f9;color:#16191d;
 font-size:12px;line-height:1.45}
.pv-head{text-align:center;margin-bottom:12px}
/* Both the avatar and the blurred cover behind it are painted by setting
   background-image from JS, so the shorthand fixes size and position here. */
.pv-ava{width:52px;height:52px;border-radius:50%;background:#e5e7eb center/cover no-repeat;
 border:1px solid #e5e7eb;display:block;margin:0 auto 8px}
.pv-name{font-size:13px;font-weight:650;margin:0;overflow-wrap:anywhere}
.pv-sub{color:#6b7280;font-size:11px;margin:3px 0 0;overflow-wrap:anywhere}
.pv-eyebrow{font-size:9.5px;letter-spacing:.09em;text-transform:uppercase;color:#6b7280;
 text-align:center;margin:0 0 3px}
.pv-big{font-size:16px;font-weight:650;text-align:center;margin:0 0 14px;overflow-wrap:anywhere}
.pv-link{
  display:flex;align-items:center;justify-content:center;gap:7px;min-height:36px;
  padding:8px 10px;margin-bottom:7px;background:#fff;border:1px solid #d7dbe0;
  border-radius:10px;font-weight:550;font-size:11.5px;text-align:center;overflow-wrap:anywhere;
}
.pv-link .pv-ic{display:inline-flex;flex:0 0 auto;color:#20262e}
.pv-link .pv-ic svg{width:14px;height:14px;fill:currentColor;display:block}
/* An outlined mark declares fill="none" and draws with its stroke; the
   blanket fill above would turn the globe and the Instagram square into
   solid blobs, so it is handed back to the attribute. */
.pv-link .pv-ic svg[fill="none"]{fill:none}
.pv-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.pv-primary .pv-ic{color:#fff}
.pv-row{display:flex;gap:7px}
.pv-row .pv-link{flex:1}
.pv-panel{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:0 10px;margin-bottom:8px}
.pv-f{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #eef0f3;font-size:11px}
.pv-panel .pv-f:last-child{border-bottom:0}
.pv-f .pv-k{color:#6b7280;flex:0 0 auto}
.pv-f .pv-v{text-align:right;overflow-wrap:anywhere}
.pv-empty{color:#9aa1ac;text-align:center;font-size:11px;padding:26px 10px;line-height:1.6}
.pv-cover{height:54px;margin:-14px -12px 10px;background:#cbd5e1 center/cover no-repeat;
 filter:blur(7px) saturate(1.15);opacity:.9}

/* ---------- Details screen: form beside its live preview ---------- */
.setup{display:grid;gap:14px}
.pv-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
.pv-lbl{font-size:.78rem;font-weight:600;color:var(--text-soft)}
.pv-hide{border:1px solid var(--line-strong);background:var(--surface);border-radius:8px;
 padding:5px 10px;font:inherit;font-size:.75rem;cursor:pointer;color:var(--text-soft)}
.pv-hide:hover{background:var(--accent-soft);border-color:var(--accent)}
.pv-side[data-collapsed="1"] .pv-phone{display:none}
@media (max-width:899px){
  /* On a phone the preview rides along at the top of the screen, small enough
     to leave room for the keyboard. */
  .pv-side{position:sticky;top:0;z-index:5;background:var(--bg);padding:8px 0 10px;
   box-shadow:0 7px 9px -9px rgba(16,24,40,.5)}
  .pv-side .pv-phone{width:180px;border-width:6px;border-radius:22px}
  .pv-side .pv-screen{height:196px;padding:11px 9px}
}
@media (min-width:900px){
  .wrap.wide{max-width:900px}
  .setup{grid-template-columns:minmax(0,1fr) 290px;align-items:start;gap:22px}
  .setup .setup-main{order:1;min-width:0}
  .setup .pv-side{order:2;position:sticky;top:24px}
}
</style>
</head>
<body>
<div class="wrap @yield('wrapclass')">
  @yield('body')
  <p class="foot">Secured by ViteSEO</p>
</div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light">
<title>@yield('title','Admin') &middot; NFC Cards</title>
@include('partials.exo2')
<script>
/* Runs BEFORE the first paint, the same trick as the platform's themeScript:
   the rail's width is on <html> before anything is drawn, so a sidebar you
   left collapsed does not flash open on every page load. `js` is also what
   reveals the collapse control - without this script it would be a button
   that does nothing, so it is never shown to a browser that skipped it. */
(function(){try{
  var d=document.documentElement;
  d.classList.add('js');
  if(localStorage.getItem('nfc-admin-nav')==='rail')d.setAttribute('data-nav','rail');
}catch(e){}})();
</script>
<style>
/* ── Light-first admin. Deliberately committed to light: no dark-mode swap. ── */
/* ── The ViteSEO palette ────────────────────────────────────────────
   Taken from the platform's own tokens (viteseo-platform2.0, globals.css)
   so a card page is recognisably from the same house as the rest of the
   systems: the same near-white page, the same white cards, the same teal.

   The teal is a FILL, never text. #4fdbd7 on white is about 1.9:1, so the
   platform pairs it with dark-teal ink (--on-accent) on top - and anything
   that has to BE teal and readable, a link or a small label, uses the
   darkened --accent-ink instead. Getting that pair the wrong way round is
   the one mistake this palette invites. */
:root{
  --bg:#fafafe;              /* platform --background      */
  --surface:#ffffff;         /* platform --card            */
  --surface-sunken:#f1f2f5;  /* platform --muted           */
  --text:#111827;            /* platform --foreground      */
  --text-soft:#374151;
  --muted:#6b7280;           /* platform --muted-foreground */
  --line:#e5e7ea;            /* platform --border          */
  --line-strong:#d3d7de;
  --accent:#4fdbd7;          /* platform --brand / --primary */
  --on-accent:#19414b;       /* platform --primary-foreground: ink ON the teal */
  --accent-ink:#0e7d79;      /* the same teal, darkened for text and links */
  --accent-soft:#eafbfa;     /* platform --accent          */
  --accent-press:#3ccfcb;
  /* Status hues are the platform's exactly; the -ink steps are those same
     hues darkened, because a badge here is small text on its own tint and
     the saturated value cannot carry that. */
  --ok:#22c554;      --ok-ink:#0f7a33;   --ok-soft:#e4f8ec;   --ok-line:#a9e6bf;
  --warn:#f0a608;    --warn-ink:#8a6000; --warn-soft:#fdf1d6; --warn-line:#f5d182;
  --danger:#f34444;  --danger-ink:#c02626; --danger-soft:#fde7e7; --danger-line:#f7b4b4;
  --radius:14px;             /* cards: the platform's 10px control radius + 4 */
  --radius-ctl:10px;         /* platform --radius                            */
  --shadow-sm:0 1px 2px rgba(16,24,40,.05);
  --shadow:0 1px 3px rgba(16,24,40,.08), 0 1px 2px rgba(16,24,40,.04);

  /* The Style Guide's type scale, the same steps the platform compiles.
     Sizes are the platform's exactly; which step each thing takes follows
     what the Style Guide names them for - page titles, section titles,
     table cells, captions, and the small uppercase label. */
  --t-h1:2.3125rem;      /* 37px - page titles            */
  --t-h2:1.6875rem;      /* 27px - card titles, figures   */
  --t-h3:1.3125rem;      /* 21px - section titles         */
  --t-body:1.1875rem;    /* 19px - descriptions           */
  --t-table:1rem;        /* 16px - table cells, inputs    */
  --t-caption:.9375rem;  /* 15px - subtitles, metadata    */
  --t-label:.8125rem;    /* 13px - uppercase small labels */

  /* ── The shell ───────────────────────────────────────────────────
     The rail is near-black on a page that is otherwise all light, and that
     is deliberate: it is the same surface every other ViteSEO system wears
     (viteseo-platform2.0, globals.css --sidebar, which stays dark in BOTH
     themes there). The frame is the house; the white page inside it is this
     system's work. Its ink is the platform's warm off-white, not pure white. */
  --sidebar:#141414;
  --sidebar-ink:#fbfbef;
  --sidebar-dim:rgba(251,251,239,.6);    /* an idle nav row       */
  --sidebar-faint:rgba(251,251,239,.5);  /* the email under a name */
  --sidebar-line:rgba(251,251,239,.1);   /* the footer hairline   */
  --nav-w:260px;                         /* open                  */
  --rail-w:72px;                         /* collapsed to icons    */
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--bg); color:var(--text);
  font:var(--t-table)/1.45 "Exo 2",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
a{color:var(--accent-ink);text-decoration:none}
a:hover{text-decoration:underline}

/* ── Shell ─────────────────────────────────────────────────── */
/* Only .pane scrolls. The rail and the bar above the content never move,
   which is what turns a long card table into a panel inside an app rather
   than a page that carries its own chrome away as you read. */
html,body{height:100%}
.shell{display:flex;height:100dvh;overflow:hidden}
.pane{flex:1;min-width:0;overflow-y:auto}

/* The phone drawer's open/closed state lives in a checkbox rather than in
   JavaScript, so the menu still opens in a browser that ran no script.
   Visually hidden but still focusable: the box IS the control, and a
   keyboard reaches it even though a pointer uses the labels. */
.navstate{position:absolute;top:0;left:0;width:1px;height:1px;opacity:0}

/* ── Sidebar ───────────────────────────────────────────────── */
.sidebar{
  position:fixed;inset:0 auto 0 0;z-index:40;
  display:flex;flex-direction:column;flex:0 0 auto;
  width:var(--nav-w);background:var(--sidebar);color:var(--sidebar-ink);
  transform:translateX(-100%);
  transition:transform .3s ease,width .3s ease;
}
.navstate:checked ~ .shell .sidebar{transform:translateX(0)}
.scrim{display:none}
.navstate:checked ~ .shell .scrim{
  display:block;position:fixed;inset:0;z-index:30;background:rgba(0,0,0,.5);
}

.side-brand{display:flex;align-items:center;gap:12px;padding:22px 22px 30px}
.brand{
  display:flex;align-items:center;gap:12px;flex:1;min-width:0;
  color:var(--sidebar-ink);font-weight:700;font-size:1.0625rem;letter-spacing:-.01em;
}
.brand:hover{text-decoration:none;color:var(--sidebar-ink)}
.brand-mark{
  flex:0 0 auto;width:40px;height:40px;border-radius:11px;
  background:var(--accent);color:var(--on-accent);
  display:grid;place-items:center;font-weight:800;font-size:1.25rem;line-height:1;
}
.brand-name{white-space:nowrap;overflow:hidden}
.side-close{
  flex:0 0 auto;display:grid;place-items:center;width:32px;height:32px;
  cursor:pointer;color:var(--sidebar-dim);
}
.side-close:hover{color:var(--sidebar-ink)}
.side-close svg{width:20px;height:20px}

.side-nav{
  flex:1;min-height:0;overflow-y:auto;padding:0 16px;
  display:flex;flex-direction:column;gap:4px;
  scrollbar-width:thin;scrollbar-color:transparent transparent;
}
.side-nav:hover{scrollbar-color:#262626 transparent}
.nrow{
  display:flex;align-items:center;gap:12px;
  padding:10px 12px;border-radius:12px;
  font-size:.875rem;font-weight:600;color:var(--sidebar-dim);
  transition:color .12s ease,background .12s ease;
}
.nrow:hover{color:var(--sidebar-ink);text-decoration:none}
.nrow.on{color:var(--accent);background:rgba(79,219,215,.1)}
.nrow:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
/* A rail is read by its icons, so every row's glyph holds ONE size rather
   than sizing to a label - which is not even there once the rail collapses. */
.nrow svg{flex:0 0 auto;width:24px;height:24px}
.nrow span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.side-foot{margin:0 22px;padding:18px 0;border-top:1px solid var(--sidebar-line)}
.side-foot-row{display:flex;align-items:center;gap:12px}
.ava{
  flex:0 0 auto;width:36px;height:36px;border-radius:50%;
  background:var(--accent);color:var(--on-accent);
  display:grid;place-items:center;font-weight:700;font-size:.85rem;
}
.who{flex:1;min-width:0}
.who-n,.who-r{margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.who-n{font-size:.875rem;font-weight:600;color:var(--sidebar-ink)}
.who-r{font-size:.78rem;color:var(--sidebar-faint)}
.signout{
  flex:0 0 auto;display:grid;place-items:center;padding:0;
  border:0;background:none;cursor:pointer;color:var(--sidebar-faint);
}
.signout:hover{color:var(--danger)}
.signout svg{width:20px;height:20px}

/* The collapse control - hidden until the script that gives it meaning has
   run, and only on screens wide enough to have a rail to collapse. */
.nav-collapse{
  position:absolute;top:58px;right:-14px;z-index:10;display:none;
  width:28px;height:28px;border-radius:50%;border:0;cursor:pointer;
  background:var(--accent);color:var(--on-accent);
  place-items:center;box-shadow:var(--shadow);
}
.nav-collapse svg{width:16px;height:16px;transition:transform .3s ease}
.nav-collapse:focus-visible{outline:2px solid var(--accent);outline-offset:2px}

@media (min-width:1024px){
  /* Back in flow: no drawer, no backdrop, and the collapse button now has a
     positioned parent to hang off. */
  .sidebar{position:relative;transform:none}
  /* There is no drawer up here, so neither the backdrop nor the control that
     closes it has anything to do. */
  .scrim,.navstate:checked ~ .shell .scrim,.side-close{display:none}
  html.js .nav-collapse{display:grid}

  html[data-nav="rail"] .sidebar{width:var(--rail-w)}
  html[data-nav="rail"] .rail-hide{display:none}
  html[data-nav="rail"] .side-brand{justify-content:center;padding:22px 12px 30px}
  html[data-nav="rail"] .brand{flex:0 0 auto;justify-content:center}
  html[data-nav="rail"] .side-nav{padding:0 12px}
  html[data-nav="rail"] .nrow{justify-content:center;padding-left:8px;padding-right:8px}
  html[data-nav="rail"] .side-foot{margin:0 12px}
  html[data-nav="rail"] .side-foot-row{flex-direction:column;gap:10px}
  html[data-nav="rail"] .nav-collapse svg{transform:rotate(180deg)}
}

/* ── Top bar ───────────────────────────────────────────────── */
/* Thin, sticky, translucent - the platform's bar exactly. It carries the
   two things that are true on every page: where you are, and the way to any
   card from anywhere. */
.topbar{
  position:sticky;top:0;z-index:20;
  display:flex;align-items:center;gap:12px;
  padding:13px 24px;border-bottom:1px solid var(--line);
  background:color-mix(in srgb,var(--bg) 85%,transparent);
  -webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);
}
.burger{
  display:grid;place-items:center;width:32px;height:32px;margin-left:-6px;
  cursor:pointer;color:var(--text);
}
.burger:hover{color:var(--text-soft)}
/* The checkbox IS the control - a pointer uses the labels, a keyboard tabs
   to the box itself. It is invisible, so its focus ring is drawn on the
   hamburger that stands for it. */
.navstate:focus-visible ~ .shell .burger{outline:2px solid var(--accent);outline-offset:2px;border-radius:6px}
.burger svg{width:24px;height:24px}
/* Small, quiet, and NOT a second <h1>: the page's own title scrolls away,
   this one stays put to say where you still are. */
.topbar-here{
  font-size:var(--t-caption);font-weight:600;color:var(--text-soft);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.gsearch{position:relative;display:flex;align-items:center;margin-left:auto}
.gsearch svg{
  position:absolute;left:11px;width:16px;height:16px;
  color:var(--muted);pointer-events:none;
}
.gsearch input[type=search]{padding-left:34px;width:250px;max-width:100%}
@media (min-width:1024px){.burger{display:none}}
@media (max-width:620px){.gsearch input[type=search]{width:150px}}

/* ── Layout ────────────────────────────────────────────────── */
/* Wide enough that a 1440p screen is not mostly gutter; the table and panels
   grow into it. Content column stays centred with modest padding. */
.container{max-width:1400px;margin:0 auto;padding:28px 24px 72px}
.page-head{margin-bottom:24px}
h1{font-size:var(--t-h1);line-height:1.15;font-weight:700;margin:0;letter-spacing:-.02em}
.page-head p{color:var(--muted);font-size:var(--t-body);line-height:1.5;margin:7px 0 0}
h2{font-size:var(--t-h3);line-height:1.25;font-weight:700;margin:0 0 16px;letter-spacing:-.01em}

.panel{
  background:var(--surface); border:1px solid var(--line);
  border-radius:var(--radius); padding:20px; margin-bottom:18px;
  box-shadow:var(--shadow-sm);
}
.panel-tight{padding:16px 20px}
.panel h2 + .sub{margin-top:-10px}
.sub{color:var(--muted);font-size:var(--t-caption);margin:0 0 14px}

/* ── Stats ─────────────────────────────────────────────────── */
.stats{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:18px}
.stat{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:14px 16px;box-shadow:var(--shadow-sm);
}
.stat .n{font-size:var(--t-h2);font-weight:700;letter-spacing:-.02em;line-height:1.2;font-variant-numeric:tabular-nums}
.stat .l{color:var(--muted);font-size:var(--t-label);line-height:1.3;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:5px}

/* ── Buttons ───────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:8px 14px;border-radius:8px;border:1px solid var(--line-strong);
  background:var(--surface);color:var(--text);font-weight:600;
  cursor:pointer;line-height:1.35;font-family:inherit;white-space:nowrap;font-size:.875rem;
  transition:background .12s ease,border-color .12s ease;
}
.btn:hover{background:var(--surface-sunken);border-color:var(--muted);text-decoration:none}
/* An icon inside a button sizes with the label, never to its own viewBox. */
.btn svg{width:14px;height:14px;flex:0 0 auto}

.btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.btn-p{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}
.btn-p:hover{background:var(--accent-press);border-color:var(--accent-press);color:var(--on-accent)}
.btn-d{color:var(--danger-ink);border-color:var(--danger-line);background:var(--surface)}
.btn-d:hover{background:var(--danger-soft);border-color:var(--danger)}
.btn-sm{padding:5px 10px;font-size:.8rem}
.btn-ghost{border-color:transparent;background:transparent;color:var(--text-soft)}
.btn-ghost:hover{background:var(--surface-sunken)}
/* ---- Read-only until Edit ----
   The whole card opens as a page you can read, and a disabled fieldset is
   what locks it: native, no JavaScript, and nothing inside one can be
   submitted either, so a locked field cannot be edited by hand or by
   devtools. */
fieldset{border:0;padding:0;margin:0;min-width:0}
fieldset:disabled input,fieldset:disabled select,fieldset:disabled textarea{
  background:var(--surface-sunken);color:var(--text);opacity:1;cursor:default;
  border-color:var(--line);
}
fieldset:disabled .btn{display:none}

/* ---- Link editor ----
   One row per link, dragged by its handle. No per-row Save and no arrows:
   the rows submit in the order they sit in. */
.lnk-list{display:flex;flex-direction:column;gap:8px}
.lnk{
  display:flex;align-items:center;gap:8px;flex-wrap:wrap;
  padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:var(--surface);
}
.lnk.dragging{opacity:.9;border-color:var(--accent);box-shadow:var(--shadow)}
.lnk .lnk-t{flex:0 1 150px;min-width:120px}
.lnk .lnk-u{flex:1 1 220px;min-width:0}
.lnk .lnk-i{flex:0 0 140px}
.lnk-on{flex:0 0 auto;width:auto;margin:0 2px}
/* An unticked box dims its own row. The state was spelled out beside the
   checkbox before - the checkbox saying it, and then the word "on" saying it
   again - and a row that looks switched off needs neither. */
.lnk:has(.lnk-on:not(:checked)){opacity:.55}
.grab{
  flex:0 0 auto;width:26px;height:32px;display:grid;place-items:center;padding:0;
  border:0;background:none;color:var(--muted);cursor:grab;touch-action:none;
}
.grab:active{cursor:grabbing}
.grab svg{width:17px;height:17px}
.grab:focus-visible{outline:2px solid var(--accent);outline-offset:1px;border-radius:6px}
.rm{
  flex:0 0 auto;width:32px;height:32px;border-radius:8px;cursor:pointer;font-family:inherit;
  border:1px solid var(--danger-line);background:var(--surface);color:var(--danger-ink);
  font-size:1.05rem;line-height:1;
}
.rm:hover{background:var(--danger-soft)}
/* Locked: the controls that only make sense while editing simply are not there. */
fieldset:disabled .grab,fieldset:disabled .rm{display:none}
@media (max-width:700px){.lnk .lnk-t,.lnk .lnk-i{flex-basis:100%}}

/* ── Forms ─────────────────────────────────────────────────── */
input[type=text],input[type=url],input[type=email],input[type=password],input[type=number],
input[type=tel],input[type=search],input[type=date],select,textarea{
  padding:9px 11px;font-size:var(--t-table);border:1px solid var(--line-strong);border-radius:var(--radius-ctl);
  background:var(--surface);color:var(--text);font-family:inherit;
}
textarea{display:block;width:100%;resize:vertical;line-height:1.55}
input::placeholder{color:#9aa1ac}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
input[type=checkbox],input[type=radio]{accent-color:var(--accent)}

/* File pickers: the native control renders a bare grey button and loose text.
   Dress the whole control as a drop-well and its button like a .btn. */
input[type=file]{
  font-size:.83rem;color:var(--text-soft);max-width:100%;
  border:1px dashed var(--line-strong);border-radius:8px;
  padding:8px 10px;background:var(--surface-sunken);font-family:inherit;
}
input[type=file]:hover{border-color:var(--muted)}
input[type=file]:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
input[type=file]::file-selector-button{
  margin-right:10px;padding:6px 12px;border-radius:7px;cursor:pointer;
  border:1px solid var(--line-strong);background:var(--surface);color:var(--text);
  font-size:.82rem;font-weight:550;font-family:inherit;
  transition:background .12s ease,border-color .12s ease;
}
input[type=file]::file-selector-button:hover{background:var(--accent-soft);border-color:var(--accent)}
label.fld{display:block;font-weight:500;font-size:.875rem;margin-bottom:6px;color:var(--text-soft)}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.row-tight{gap:6px}

/* ── Toolbar: labelled groups of related actions ───────────── */
.toolbar{display:flex;gap:18px 40px;flex-wrap:wrap;justify-content:space-between}
.tool-group{min-width:0}
.tool-label{
  display:block;font-size:var(--t-label);line-height:1.3;font-weight:700;color:var(--muted);
  text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;
}

/* ── Field grid: labelled inputs in responsive columns ─────── */
/* Lives here, not in card.blade.php: the sticker templates page uses it too,
   and it once rendered unstyled there because the rules were page-local. */
.fgrid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:16px}
.fgrid label{display:flex;flex-direction:column;gap:5px}
.fgrid label.wide{grid-column:1/-1}
.fgrid label span{font-size:.79rem;color:var(--text-soft);font-weight:530}
.fgrid input,.fgrid textarea{
  padding:9px 11px;font-size:var(--t-table);border:1px solid var(--line-strong);border-radius:var(--radius-ctl);
  background:var(--surface);color:var(--text);font-family:inherit;width:100%;
}
.fgrid textarea{resize:vertical;line-height:1.5}
.fgrid input:focus,.fgrid textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}

/* ── Badges ────────────────────────────────────────────────── */
.badge{
  display:inline-flex;align-items:center;padding:2px 9px;border-radius:20px;
  font-size:var(--t-caption);font-weight:500;white-space:nowrap;border:1px solid transparent;
}
.b-ok{background:var(--ok-soft);color:var(--ok-ink);border-color:var(--ok-line)}
.b-off{background:var(--surface-sunken);color:var(--muted);border-color:var(--line-strong)}
.b-info{background:var(--accent-soft);color:var(--accent-ink);border-color:#b8ecea}

/* ── Table ─────────────────────────────────────────────────── */
.tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -20px;padding:0 20px}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
th{color:var(--muted);font-size:var(--t-label);line-height:1.3;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:var(--surface-sunken)}
th:first-child{border-radius:7px 0 0 7px} th:last-child{border-radius:0 7px 7px 0}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:var(--surface-sunken)}

/* ── Mobile: collapse the table into card rows ─────────────── */
@media (max-width:720px){
  .tablewrap{overflow:visible;margin:0;padding:0}
  table,thead,tbody,th,td,tr{display:block}
  thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  tr{
    border:1px solid var(--line);border-radius:var(--radius);
    padding:12px 14px;margin-bottom:10px;background:var(--surface);
  }
  tbody tr:hover{background:var(--surface)}
  td{border:0;padding:4px 0;display:flex;justify-content:space-between;align-items:center;gap:14px}
  td::before{
    content:attr(data-label);color:var(--muted);font-size:.75rem;
    font-weight:600;flex:0 0 auto;
  }
  td:last-child{padding-top:10px;justify-content:flex-end}
  td:last-child::before{content:""}
}

/* ── Misc ──────────────────────────────────────────────────── */
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.84em}
code{background:var(--surface-sunken);border:1px solid var(--line);padding:2px 7px;border-radius:5px;word-break:break-all;color:var(--text-soft)}
.muted{color:var(--muted)}
.small{font-size:var(--t-caption)}
.flash{background:var(--ok-soft);border:1px solid var(--ok-line);color:var(--ok-ink);padding:11px 14px;border-radius:8px;margin-bottom:18px;font-size:.88rem}
.err{background:var(--danger-soft);border:1px solid var(--danger-line);color:var(--danger-ink);padding:11px 14px;border-radius:8px;margin-bottom:18px;font-size:.88rem}
.err ul{margin:0;padding-left:18px}
.note{background:var(--surface-sunken);border:1px solid var(--line);border-left:3px solid var(--line-strong);padding:10px 14px;border-radius:7px;font-size:.83rem;color:var(--text-soft);margin:12px 0 0}
hr{border:0;border-top:1px solid var(--line);margin:18px 0}
.section-sep{border-top:1px solid var(--line);margin:18px -20px;padding-top:18px;padding-left:20px;padding-right:20px}

/* Danger zone: visually separated from normal actions */
.danger-zone{border-color:var(--danger-line);background:var(--danger-soft)}
.danger-zone h2{color:var(--danger-ink)}

.pager{margin-top:16px}
.pager-nav{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.pager-nav a,.pager-nav span{
  min-width:32px;text-align:center;padding:6px 11px;border-radius:7px;
  border:1px solid var(--line);font-size:.82rem;background:var(--surface);color:var(--text);
}
.pager-nav a:hover{background:var(--surface-sunken);border-color:var(--line-strong);text-decoration:none}
.pager-nav a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.pager-nav .pg-on{background:var(--accent);border-color:var(--accent);color:var(--on-accent);font-weight:600}
.pager-nav .pg-off{color:var(--muted);background:var(--surface-sunken)}
.pager-nav .pg-count{margin-left:auto;border:0;background:transparent;color:var(--muted);min-width:0}
</style>
</head>
<body>
{{-- The drawer's state. It sits outside .shell so the sidebar and the
     backdrop can both be written as siblings of a :checked box. --}}
<input class="navstate" type="checkbox" id="navstate" aria-label="Show navigation menu">

<div class="shell">
  <label class="scrim" for="navstate" aria-hidden="true"></label>
  @include('admin.partials.sidebar')

  <div class="pane">
    <header class="topbar">
      <label class="burger" for="navstate" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </label>
      <span class="topbar-here">@yield('title','Admin')</span>

      {{-- One search box for the whole admin: from any page it lands on the
           card list already filtered. The dashboard's own status and sort
           ride along as hidden fields so searching from there narrows what
           you are looking at instead of resetting it. --}}
      <form class="gsearch" method="GET" action="{{ route('admin.dashboard') }}" role="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="search" value="{{ request()->query('search') }}"
               placeholder="Search cards" aria-label="Search cards by number or public ID">
        @if (request()->filled('status'))<input type="hidden" name="status" value="{{ request()->query('status') }}">@endif
        @if (request()->filled('sort'))<input type="hidden" name="sort" value="{{ request()->query('sort') }}">@endif
      </form>
    </header>

    <main class="container">
      @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
      @if ($errors->any())<div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
      @yield('body')
    </main>
  </div>
</div>

<script>
(function () {
  var html = document.documentElement;
  var button = document.getElementById('nav-collapse');
  var drawer = document.getElementById('navstate');

  function label() {
    if (! button) return;
    var rail = html.getAttribute('data-nav') === 'rail';
    button.setAttribute('aria-expanded', String(! rail));
    button.setAttribute('aria-label', rail ? 'Expand sidebar' : 'Collapse sidebar');
  }

  // The state was restored before paint, so the button's own labelling is
  // whatever the server rendered until this runs.
  label();

  if (button) {
    button.addEventListener('click', function () {
      var rail = html.getAttribute('data-nav') === 'rail';
      if (rail) html.removeAttribute('data-nav'); else html.setAttribute('data-nav', 'rail');
      label();
      try { localStorage.setItem('nfc-admin-nav', rail ? 'open' : 'rail'); } catch (e) {}
    });
  }

  // Escape closes the phone drawer, matching the platform's shell.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawer && drawer.checked) drawer.checked = false;
  });
})();
</script>
</body>
</html>

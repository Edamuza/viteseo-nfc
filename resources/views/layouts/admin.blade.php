<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light">
<title>@yield('title','Admin') &middot; NFC Cards</title>
<style>
/* ── Light-first admin. Deliberately committed to light: no dark-mode swap. ── */
:root{
  --bg:#f7f8fa;
  --surface:#ffffff;
  --surface-sunken:#f9fafb;
  --text:#16191d;
  --text-soft:#4a5260;
  --muted:#6b7280;
  --line:#e5e7eb;
  --line-strong:#d3d8df;
  --accent:#2563eb;
  --accent-soft:#eff4ff;
  --accent-press:#1d4ed8;
  --ok:#047857;      --ok-soft:#ecfdf5;   --ok-line:#a7f3d0;
  --warn:#b45309;    --warn-soft:#fffbeb; --warn-line:#fde68a;
  --danger:#b42318;  --danger-soft:#fef3f2; --danger-line:#fecdca;
  --radius:10px;
  --shadow-sm:0 1px 2px rgba(16,24,40,.05);
  --shadow:0 1px 3px rgba(16,24,40,.08), 0 1px 2px rgba(16,24,40,.04);
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--bg); color:var(--text);
  font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}

/* ── Top bar ───────────────────────────────────────────────── */
.topbar{
  background:var(--surface); border-bottom:1px solid var(--line);
  padding:0 20px; display:flex; align-items:center; gap:22px;
  height:56px; position:sticky; top:0; z-index:20;
}
.brand{font-weight:640;letter-spacing:-.01em;color:var(--text);font-size:.98rem;white-space:nowrap}
.brand:hover{text-decoration:none}
/* The nav scrolls sideways instead of overflowing the bar on narrow phones. */
.topbar nav{display:flex;gap:4px;flex:1;min-width:0;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.topbar nav::-webkit-scrollbar{display:none}
.topbar nav a{
  color:var(--text-soft);font-weight:530;font-size:.9rem;
  padding:6px 11px;border-radius:7px;white-space:nowrap;
}
.topbar nav a:hover{background:var(--surface-sunken);color:var(--text);text-decoration:none}
.topbar nav a.on{background:var(--accent-soft);color:var(--accent)}

/* ── Layout ────────────────────────────────────────────────── */
.container{max-width:1120px;margin:0 auto;padding:28px 20px 72px}
.page-head{margin-bottom:24px}
h1{font-size:1.35rem;font-weight:640;margin:0;letter-spacing:-.02em}
.page-head p{color:var(--muted);font-size:.9rem;margin:5px 0 0}
h2{font-size:.95rem;font-weight:620;margin:0 0 16px;letter-spacing:-.005em}

.panel{
  background:var(--surface); border:1px solid var(--line);
  border-radius:var(--radius); padding:20px; margin-bottom:18px;
  box-shadow:var(--shadow-sm);
}
.panel-tight{padding:16px 20px}
.panel h2 + .sub{margin-top:-10px}
.sub{color:var(--muted);font-size:.85rem;margin:0 0 14px}

/* ── Stats ─────────────────────────────────────────────────── */
.stats{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:18px}
.stat{
  background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:14px 16px;box-shadow:var(--shadow-sm);
}
.stat .n{font-size:1.6rem;font-weight:650;letter-spacing:-.03em;line-height:1.15}
.stat .l{color:var(--muted);font-size:.78rem;font-weight:530;margin-top:3px}

/* ── Buttons ───────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:8px 14px;border-radius:8px;border:1px solid var(--line-strong);
  background:var(--surface);color:var(--text);font-size:.875rem;font-weight:550;
  cursor:pointer;line-height:1.35;font-family:inherit;white-space:nowrap;
  transition:background .12s ease,border-color .12s ease;
}
.btn:hover{background:var(--surface-sunken);border-color:var(--muted);text-decoration:none}
.btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.btn-p{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-p:hover{background:var(--accent-press);border-color:var(--accent-press);color:#fff}
.btn-d{color:var(--danger);border-color:var(--danger-line);background:var(--surface)}
.btn-d:hover{background:var(--danger-soft);border-color:var(--danger)}
.btn-sm{padding:5px 10px;font-size:.8rem}
.btn-ghost{border-color:transparent;background:transparent;color:var(--text-soft)}
.btn-ghost:hover{background:var(--surface-sunken)}

/* ── Forms ─────────────────────────────────────────────────── */
input[type=text],input[type=url],input[type=email],input[type=password],input[type=number],select,textarea{
  padding:8px 11px;font-size:.88rem;border:1px solid var(--line-strong);border-radius:8px;
  background:var(--surface);color:var(--text);font-family:inherit;
}
textarea{display:block;width:100%;resize:vertical;line-height:1.55}
input::placeholder{color:#9aa1ac}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
label.fld{display:block;font-weight:570;font-size:.83rem;margin-bottom:6px;color:var(--text-soft)}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.row-tight{gap:6px}

/* ── Toolbar: labelled groups of related actions ───────────── */
.toolbar{display:flex;gap:18px 40px;flex-wrap:wrap;justify-content:space-between}
.tool-group{min-width:0}
.tool-label{
  display:block;font-size:.72rem;font-weight:620;color:var(--muted);
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
  padding:8px 11px;font-size:.88rem;border:1px solid var(--line-strong);border-radius:8px;
  background:var(--surface);color:var(--text);font-family:inherit;width:100%;
}
.fgrid textarea{resize:vertical;line-height:1.5}
.fgrid input:focus,.fgrid textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}

/* ── Badges ────────────────────────────────────────────────── */
.badge{
  display:inline-flex;align-items:center;padding:2px 9px;border-radius:20px;
  font-size:.75rem;font-weight:580;white-space:nowrap;border:1px solid transparent;
}
.b-ok{background:var(--ok-soft);color:var(--ok);border-color:var(--ok-line)}
.b-off{background:var(--surface-sunken);color:var(--muted);border-color:var(--line-strong)}
.b-info{background:var(--accent-soft);color:var(--accent);border-color:#c7d9fd}

/* ── Table ─────────────────────────────────────────────────── */
.tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -20px;padding:0 20px}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
th{color:var(--muted);font-size:.75rem;font-weight:600;background:var(--surface-sunken)}
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
.small{font-size:.83rem}
.flash{background:var(--ok-soft);border:1px solid var(--ok-line);color:var(--ok);padding:11px 14px;border-radius:8px;margin-bottom:18px;font-size:.88rem}
.err{background:var(--danger-soft);border:1px solid var(--danger-line);color:var(--danger);padding:11px 14px;border-radius:8px;margin-bottom:18px;font-size:.88rem}
.err ul{margin:0;padding-left:18px}
.note{background:var(--surface-sunken);border:1px solid var(--line);border-left:3px solid var(--line-strong);padding:10px 14px;border-radius:7px;font-size:.83rem;color:var(--text-soft);margin:12px 0 0}
hr{border:0;border-top:1px solid var(--line);margin:18px 0}
.section-sep{border-top:1px solid var(--line);margin:18px -20px;padding-top:18px;padding-left:20px;padding-right:20px}

/* Danger zone: visually separated from normal actions */
.danger-zone{border-color:var(--danger-line);background:var(--danger-soft)}
.danger-zone h2{color:var(--danger)}

.pager{margin-top:16px}
.pager-nav{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.pager-nav a,.pager-nav span{
  min-width:32px;text-align:center;padding:6px 11px;border-radius:7px;
  border:1px solid var(--line);font-size:.82rem;background:var(--surface);color:var(--text);
}
.pager-nav a:hover{background:var(--surface-sunken);border-color:var(--line-strong);text-decoration:none}
.pager-nav a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.pager-nav .pg-on{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
.pager-nav .pg-off{color:var(--muted);background:var(--surface-sunken)}
.pager-nav .pg-count{margin-left:auto;border:0;background:transparent;color:var(--muted);min-width:0}
</style>
</head>
<body>
<header class="topbar">
  <a class="brand" href="{{ route('admin.dashboard') }}">NFC Cards</a>
  <nav>
    <a href="{{ route('admin.dashboard') }}" @class(['on' => request()->routeIs('admin.dashboard') || request()->routeIs('admin.cards.*')])>Cards</a>
    <a href="{{ route('admin.nfc-tools') }}" @class(['on' => request()->routeIs('admin.nfc-tools')])>NFC Tools</a>
    <a href="{{ route('admin.site-pages.index') }}" @class(['on' => request()->routeIs('admin.site-pages.*')])>Site Pages</a>
    <a href="{{ route('admin.sticker-templates.index') }}" @class(['on' => request()->routeIs('admin.sticker-templates.*')])>Sticker templates</a>
  </nav>
  <form method="POST" action="{{ route('admin.logout') }}">@csrf
    <button class="btn btn-sm" type="submit">Sign out</button>
  </form>
</header>

<main class="container">
  @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  @yield('body')
</main>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title>Sign in &middot; NFC Cards</title>
<style>
:root{--bg:#f7f8fa;--surface:#fff;--text:#16191d;--muted:#6b7280;--line:#e5e7eb;
 --line-strong:#d3d8df;--accent:#2563eb;--accent-soft:#eff4ff;--danger:#b42318;
 --danger-soft:#fef3f2;--danger-line:#fecdca}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);min-height:100dvh;display:flex;
 align-items:center;justify-content:center;padding:24px;
 font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
 -webkit-font-smoothing:antialiased}
.box{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:30px;
 width:100%;max-width:370px;box-shadow:0 1px 3px rgba(16,24,40,.08)}
h1{font-size:1.15rem;font-weight:640;margin:0 0 4px;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:.85rem;margin:0 0 22px}
label{display:block;font-weight:570;font-size:.83rem;margin:14px 0 6px}
input{width:100%;padding:10px 12px;font-size:.95rem;border:1px solid var(--line-strong);
 border-radius:8px;background:var(--surface);color:var(--text)}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
button{width:100%;margin-top:22px;padding:11px;font-size:.92rem;font-weight:570;border:0;
 border-radius:8px;background:var(--accent);color:#fff;cursor:pointer;font-family:inherit}
button:hover{background:#1d4ed8}
.err{background:var(--danger-soft);border:1px solid var(--danger-line);color:var(--danger);
 padding:10px 13px;border-radius:8px;margin-bottom:16px;font-size:.85rem}
.err ul{margin:0;padding-left:18px}
</style>
</head>
<body>
<form class="box" method="POST" action="{{ route('admin.login.attempt') }}">
  @csrf
  <h1>NFC Cards</h1>
  <p class="sub">Sign in to manage your cards.</p>
  @if ($errors->any())<div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  <label for="email">Email</label>
  <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
  <label for="password">Password</label>
  <input id="password" name="password" type="password" required autocomplete="current-password">
  <button type="submit">Sign in</button>
</form>
</body>
</html>

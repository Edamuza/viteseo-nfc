@extends('layouts.public')
@section('title','Set up this card')
@section('body')
<div class="card">
  <h1>Set up your card</h1>
  <p class="sub">Add one link to go straight there, or several to show a simple menu when someone taps your card.</p>

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('nfc.store', $card->public_id) }}">
    @csrf
    <label for="activation_pin">Activation PIN</label>
    <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
           maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>

    <label for="page_title">Business name <span class="opt">(optional)</span></label>
    <input id="page_title" name="page_title" type="text" maxlength="120" value="{{ old('page_title') }}">

    <label for="page_subtitle">Short tagline <span class="opt">(optional)</span></label>
    <input id="page_subtitle" name="page_subtitle" type="text" maxlength="200" value="{{ old('page_subtitle') }}">

    <label>Your links</label>
    <div id="links">
      @php $old = old('links', [['title'=>'','url'=>'']]); @endphp
      @foreach ($old as $i => $row)
        <div class="linkrow">
          <input class="t" name="links[{{ $i }}][title]" type="text" placeholder="Label" maxlength="120" value="{{ $row['title'] ?? '' }}" required>
          <input name="links[{{ $i }}][url]" type="url" inputmode="url" placeholder="https://…" value="{{ $row['url'] ?? '' }}" required>
        </div>
      @endforeach
    </div>
    <button type="button" class="ghost" id="add">+ Add another link</button>

    <button class="primary" type="submit">Save my card</button>
  </form>
</div>

<script>
// Progressive enhancement only: the form works with one link if JS is off.
(function () {
  var wrap = document.getElementById('links');
  var i = wrap.querySelectorAll('.linkrow').length;
  document.getElementById('add').addEventListener('click', function () {
    if (i >= 12) return;
    var row = document.createElement('div');
    row.className = 'linkrow';
    var t = document.createElement('input');
    t.className = 't'; t.type = 'text'; t.placeholder = 'Label';
    t.maxLength = 120; t.required = true; t.name = 'links[' + i + '][title]';
    var u = document.createElement('input');
    u.type = 'url'; u.placeholder = 'https://…'; u.required = true;
    u.name = 'links[' + i + '][url]';
    row.appendChild(t); row.appendChild(u); wrap.appendChild(row);
    i++; t.focus();
  });
})();
</script>
@endsection

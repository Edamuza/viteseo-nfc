@extends('layouts.public')
@section('title','Add your links')
@section('wrapclass','wide')
@section('body')
{{-- Step 3 of 3: details only. The PIN was settled on the unlock screen, so
     everything here is about the card itself, beside a preview that redraws
     on every keystroke. --}}
<div class="setup">
  @include('public.partials.preview-live', ['kind' => 'links'])

  <div class="setup-main">
    <div class="card">
      @include('public.partials.steps', ['step' => 3])

      <h1>Add your links</h1>
      <p class="sub">Each link becomes a button.</p>

      @if ($errors->any())
        <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif

      <form method="POST" action="{{ route('nfc.store', $card->public_id) }}">
        @csrf

        <label for="page_title">Title at the top <span class="opt">(optional)</span></label>
        <input id="page_title" name="page_title" type="text" maxlength="120"
               placeholder="e.g. Juan&rsquo;s Coffee" value="{{ old('page_title', $card->nfc_page_title) }}">

        <label for="page_subtitle">One line under the title <span class="opt">(optional)</span></label>
        <input id="page_subtitle" name="page_subtitle" type="text" maxlength="200"
               placeholder="e.g. Fresh beans, Manila" value="{{ old('page_subtitle', $card->nfc_page_subtitle) }}">

        <label>Your buttons</label>
        <div id="links">
          @php
            // Prefilled with the card's saved links when switching modes from
            // the manage page; a fresh card starts with one empty row.
            $existing = ($existingLinks ?? collect())
                ->map(fn ($l) => ['title' => $l->title, 'url' => $l->url, 'icon' => (string) $l->icon])
                ->values()->all();
            $old = old('links', $existing ?: [['title'=>'','url'=>'','icon'=>'']]);
          @endphp
          @foreach ($old as $i => $row)
            <div class="linkrow">
              <input class="t" name="links[{{ $i }}][title]" type="text" placeholder="Button name" maxlength="120" value="{{ $row['title'] ?? '' }}" required>
              <input name="links[{{ $i }}][url]" type="url" inputmode="url" placeholder="https://…" value="{{ $row['url'] ?? '' }}" required>
              <select class="ico" name="links[{{ $i }}][icon]" aria-label="Logo">
                <option value="">Pick automatically</option>
                @foreach (\App\Services\LinkPlatform::iconChoices() as $key => $label)
                  <option value="{{ $key }}" @selected(($row['icon'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
              </select>
            </div>
          @endforeach
        </div>
        <button type="button" class="ghost" id="add">+ Add another button</button>

        <div class="actions">
          @include('public.partials.back-to-types')
          <button class="primary" type="submit">Save and go live</button>
        </div>
      </form>

      @include('public.partials.card-id')
    </div>
  </div>
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
    t.className = 't'; t.type = 'text'; t.placeholder = 'Button name';
    t.maxLength = 120; t.required = true; t.name = 'links[' + i + '][title]';
    var u = document.createElement('input');
    u.type = 'url'; u.placeholder = 'https://…'; u.required = true;
    u.name = 'links[' + i + '][url]';
    // Clone the first row's logo select so the choices stay in one place.
    var s = wrap.querySelector('select.ico').cloneNode(true);
    s.name = 'links[' + i + '][icon]'; s.selectedIndex = 0;
    row.appendChild(t); row.appendChild(u); row.appendChild(s); wrap.appendChild(row);
    i++; t.focus();
  });
})();
</script>
@endsection

@extends('layouts.public')
@section('title','Your profile page')
@section('wrapclass', $unlocked ? 'wide' : '')
@section('body')
<style>
  .editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
  .wide-field{grid-column:1/-1}
  input[type=file]{
    width:100%;padding:10px;border:1px dashed var(--line-strong);border-radius:9px;
    background:#f9fafb;color:var(--text-soft);font-size:.9rem;font-family:inherit;
  }
  input[type=file]:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
  input[type=file]::file-selector-button{
    margin-right:10px;padding:7px 13px;border-radius:8px;cursor:pointer;
    border:1px solid var(--line-strong);background:var(--surface);color:var(--text);
    font-size:.85rem;font-weight:550;font-family:inherit;
  }
  input[type=file]::file-selector-button:hover{background:var(--accent-soft);border-color:var(--accent)}
  .avatar-preview{width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--line);margin-top:8px}
  .check{display:flex;align-items:center;gap:8px;font-weight:500}
  .links{margin-top:22px;padding-top:4px;border-top:1px solid var(--line)}
  .links-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
  .links-head h2{font-size:1rem;margin:15px 0 7px}
  /* A real button, not a floating ×: input-height, red-bordered, with hover
     and focus states, sitting at the end of the label/URL line. */
  .remove-link{
    flex:0 0 auto;width:44px;align-self:stretch;min-height:46px;
    display:inline-flex;align-items:center;justify-content:center;
    border:1px solid var(--danger-line);border-radius:9px;cursor:pointer;
    background:var(--surface);color:var(--danger);font-size:1.15rem;line-height:1;
    font-family:inherit;transition:background .12s ease,border-color .12s ease;
  }
  .remove-link:hover{background:var(--danger-soft);border-color:var(--danger)}
  .remove-link:focus-visible{outline:2px solid var(--danger);outline-offset:2px}
  .status{background:var(--ok-soft);border:1px solid var(--ok-line);color:var(--ok-ink);padding:11px 13px;border-radius:9px;margin-bottom:18px}
  @media(max-width:560px){.editor-grid{grid-template-columns:1fr}.wide-field{grid-column:auto}}
</style>

{{-- Profile is set up in the same three steps as the other card types; this
     page is both step 2 (the PIN) and step 3 (the details).

     The two-column grid goes on ONLY when there is a preview to put in the
     second column. Locked, this screen is one card and nothing else, and a
     grid with two tracks and one child squeezed that card into a sliver of
     the first track on every screen wide enough to have both. --}}
<div @class(['setup' => $unlocked])>
  @if ($unlocked)
    @include('public.partials.preview-live', [
      'kind' => 'profile',
      'avatarUrl' => $profile?->avatar_path ? asset('storage/'.$profile->avatar_path) : '',
    ])
  @endif

  <div class="setup-main">
    <div class="card">
      @include('public.partials.steps', ['step' => $unlocked ? 3 : 2])

      @if ($unlocked)
        <h1>Your profile page</h1>
      @else
        <h1>Unlock your card</h1>
      @endif

      @if ($errors->any())
        <div class="err"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
      @endif
      @if (session('status'))<div class="status">{{ session('status') }}</div>@endif

      @if (! $unlocked)
        <form method="POST" action="{{ route('nfc.profile.edit.unlock', $card->public_id) }}">
          @csrf
          <label for="activation_pin">{{ $card->pinIsPersonalized() ? 'Your card PIN' : 'Activation PIN' }}</label>
          <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
                 maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required autofocus>
          @include('public.partials.new-pin')
          <div class="actions">
            @include('public.partials.back-to-types')
            <button class="primary" type="submit">Continue</button>
          </div>
        </form>
      @else
        @php
          $linkRows = old('links');
          if ($linkRows === null) {
              $linkRows = $links->map(fn ($link) => ['title' => $link->title, 'url' => $link->url, 'icon' => $link->icon])->all();
          }
          if (count($linkRows) < 12) {
              $linkRows[] = ['title' => '', 'url' => '', 'icon' => ''];
          }
        @endphp
        <form method="POST" action="{{ route('nfc.profile.edit.update', $card->public_id) }}" enctype="multipart/form-data">
          @csrf
          @method('PATCH')
          <div class="editor-grid">
            <div>
              <label for="display_name">Your name</label>
              <input id="display_name" name="display_name" type="text" maxlength="120"
                     placeholder="e.g. John Doe" value="{{ old('display_name', $profile?->display_name) }}">
            </div>
            <div>
              <label for="username">Username <span class="opt">(optional)</span></label>
              <input id="username" name="username" type="text" maxlength="60"
                     placeholder="e.g. johndoe" value="{{ old('username', $profile?->username) }}">
            </div>
            <div class="wide-field">
              <label for="headline">What you do <span class="opt">(optional)</span></label>
              <input id="headline" name="headline" type="text" maxlength="200"
                     placeholder="e.g. Owner at Acme Inc." value="{{ old('headline', $profile?->headline) }}">
            </div>
            <div>
              <label for="location">Where you are <span class="opt">(optional)</span></label>
              <input id="location" name="location" type="text" maxlength="100"
                     placeholder="e.g. Manila" value="{{ old('location', $profile?->location) }}">
            </div>
            <div>
              <label for="tagline">Short line about you <span class="opt">(optional)</span></label>
              <input id="tagline" name="tagline" type="text" maxlength="200"
                     placeholder="e.g. Always happy to connect" value="{{ old('tagline', $profile?->tagline) }}">
            </div>
            <div class="wide-field">
              <label for="avatar">Your photo <span class="opt">(JPG, PNG or WebP, up to 2 MB)</span></label>
              <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
              @if ($profile?->avatar_path)
                <img class="avatar-preview" src="{{ asset('storage/'.$profile->avatar_path) }}" alt="Your current photo">
                <label class="check"><input name="remove_avatar" type="checkbox" value="1"> Remove this photo</label>
              @endif
            </div>
          </div>

          <section class="links">
            <div class="links-head">
              <h2>Your links</h2>
              <button class="ghost" id="add-link" type="button" style="width:auto;margin:0">Add link</button>
            </div>
            <p class="sub">Up to 12 links.</p>
            <div id="link-rows">
              @foreach ($linkRows as $index => $link)
                <div class="linkrow">
                  <input class="t" name="links[{{ $index }}][title]" type="text" maxlength="120"
                         value="{{ $link['title'] ?? '' }}" placeholder="Link name">
                  <input name="links[{{ $index }}][url]" type="url" maxlength="500"
                         value="{{ $link['url'] ?? '' }}" placeholder="https://example.com">
                  <button class="remove-link" type="button" aria-label="Remove link">&times;</button>
                  <select name="links[{{ $index }}][icon]" aria-label="Logo">
                    <option value="">Pick automatically</option>
                    @foreach (\App\Services\LinkPlatform::iconChoices() as $key => $label)
                      <option value="{{ $key }}" @selected(($link['icon'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                  </select>
                </div>
              @endforeach
            </div>
          </section>

          <div class="actions">
            @include('public.partials.back-to-types')
            <button class="primary" type="submit">Save and go live</button>
          </div>
        </form>
        <template id="link-template">
          <div class="linkrow">
            <input class="t" name="links[__INDEX__][title]" type="text" maxlength="120" placeholder="Link name">
            <input name="links[__INDEX__][url]" type="url" maxlength="500" placeholder="https://example.com">
            <button class="remove-link" type="button" aria-label="Remove link">&times;</button>
            <select name="links[__INDEX__][icon]" aria-label="Logo">
              <option value="">Pick automatically</option>
              @foreach (\App\Services\LinkPlatform::iconChoices() as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
              @endforeach
            </select>
          </div>
        </template>
        <script>
          (() => {
            const rows = document.getElementById('link-rows');
            const add = document.getElementById('add-link');
            const template = document.getElementById('link-template');
            const remove = event => {
              const button = event.target.closest('.remove-link');
              if (button) button.closest('.linkrow').remove();
            };
            rows.addEventListener('click', remove);
            add.addEventListener('click', () => {
              if (rows.children.length >= 12) return;
              const index = Date.now().toString();
              rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
            });
          })();
        </script>
      @endif

      @include('public.partials.card-id')
    </div>
  </div>
</div>
@endsection

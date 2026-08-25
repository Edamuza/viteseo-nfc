@extends('layouts.public')
@section('title','Edit profile')
@section('body')
<style>
  .wrap{max-width:680px}
  .editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
  .wide{grid-column:1/-1}
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
  .remove-link{border:0;background:transparent;color:var(--danger);cursor:pointer;padding:8px}
  .status{background:#ecfdf3;border:1px solid #abefc6;color:#067647;padding:11px 13px;border-radius:9px;margin-bottom:18px}
  @media(max-width:560px){.editor-grid{grid-template-columns:1fr}.wide{grid-column:auto}.linkrow{display:block}.linkrow .t{margin-bottom:8px}}
</style>
<div class="card">
  <h1>Edit your profile</h1>
  <p class="sub">{{ $unlocked ? 'Update the details shown on your NFC profile.' : 'Enter the activation PIN supplied with your card.' }}</p>

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif
  @if (session('status'))<div class="status">{{ session('status') }}</div>@endif

  @if (! $unlocked)
    <form method="POST" action="{{ route('nfc.profile.edit.unlock', $card->public_id) }}">
      @csrf
      <label for="activation_pin">Activation PIN</label>
      <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
             maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>
      <button class="primary" type="submit">Continue</button>
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
          <label for="display_name">Display name</label>
          <input id="display_name" name="display_name" type="text" maxlength="120"
                 value="{{ old('display_name', $profile?->display_name) }}">
        </div>
        <div>
          <label for="username">Username <span class="opt">(optional)</span></label>
          <input id="username" name="username" type="text" maxlength="60"
                 value="{{ old('username', $profile?->username) }}">
        </div>
        <div class="wide">
          <label for="headline">Headline <span class="opt">(optional)</span></label>
          <input id="headline" name="headline" type="text" maxlength="200"
                 value="{{ old('headline', $profile?->headline) }}">
        </div>
        <div>
          <label for="location">Location <span class="opt">(optional)</span></label>
          <input id="location" name="location" type="text" maxlength="100"
                 value="{{ old('location', $profile?->location) }}">
        </div>
        <div>
          <label for="tagline">Tagline <span class="opt">(optional)</span></label>
          <input id="tagline" name="tagline" type="text" maxlength="200"
                 value="{{ old('tagline', $profile?->tagline) }}">
        </div>
        <div class="wide">
          <label for="avatar">Profile photo <span class="opt">(JPG, PNG, or WebP; max 2 MB)</span></label>
          <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
          @if ($profile?->avatar_path)
            <img class="avatar-preview" src="{{ asset('storage/'.$profile->avatar_path) }}" alt="Current profile photo">
            <label class="check"><input name="remove_avatar" type="checkbox" value="1"> Remove current photo</label>
          @endif
        </div>
      </div>

      <section class="links">
        <div class="links-head">
          <h2>Links</h2>
          <button class="ghost" id="add-link" type="button" style="width:auto;margin:0">Add link</button>
        </div>
        <p class="sub">Add up to 12 secure web links. The logo dropdown picks the icon shown
          beside each link &mdash; a platform mark, the globe for your own site, or
          &ldquo;No logo&rdquo; for plain text; &ldquo;Auto&rdquo; matches the URL by itself.</p>
        <div id="link-rows">
          @foreach ($linkRows as $index => $link)
            <div class="linkrow">
              <input class="t" name="links[{{ $index }}][title]" type="text" maxlength="120"
                     value="{{ $link['title'] ?? '' }}" placeholder="Title">
              <input name="links[{{ $index }}][url]" type="url" maxlength="500"
                     value="{{ $link['url'] ?? '' }}" placeholder="https://example.com">
              <button class="remove-link" type="button" aria-label="Remove link">&times;</button>
              <select name="links[{{ $index }}][icon]" aria-label="Logo">
                <option value="">Auto logo</option>
                @foreach (\App\Services\LinkPlatform::iconChoices() as $key => $label)
                  <option value="{{ $key }}" @selected(($link['icon'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
              </select>
            </div>
          @endforeach
        </div>
      </section>

      <button class="primary" type="submit">Save profile</button>
    </form>
    <template id="link-template">
      <div class="linkrow">
        <input class="t" name="links[__INDEX__][title]" type="text" maxlength="120" placeholder="Title">
        <input name="links[__INDEX__][url]" type="url" maxlength="500" placeholder="https://example.com">
        <button class="remove-link" type="button" aria-label="Remove link">&times;</button>
        <select name="links[__INDEX__][icon]" aria-label="Logo">
          <option value="">Auto logo</option>
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

  <p style="margin:18px 0 0"><a href="{{ route('nfc.show', $card->public_id) }}">&larr; Back to profile</a></p>
</div>
@endsection

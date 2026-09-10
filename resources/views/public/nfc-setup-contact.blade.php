@extends('layouts.public')
@section('title','Fill in your contact card')
@section('wrapclass','wide')
@section('body')
@php
  // What the card's profile page offers. Any field left blank below is filled
  // from it on every tap (see ContactCard), so the form shows those values as
  // placeholders and the preview draws them.
  $fb = $fromProfile ?? [];
  $ownPhoto = $contactProfile?->photo_path;
  $profilePhoto = isset($fb['photo_path']) ? asset('storage/'.$fb['photo_path']) : '';
@endphp
<style>
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
  .from-profile{display:flex;align-items:center;gap:12px;margin:0 0 16px;padding:12px 14px;
    border:1px solid var(--line);border-radius:10px;background:var(--accent-soft);font-size:.9rem;color:var(--text-soft)}
  .from-profile img{width:40px;height:40px;border-radius:50%;object-fit:cover;flex:0 0 auto}
  .photo-note{margin:6px 0 0;font-size:.85rem;color:var(--muted)}
</style>
<div class="setup">
  @include('public.partials.preview-live', [
    'kind' => 'contact',
    'avatarUrl' => $ownPhoto ? asset('storage/'.$ownPhoto) : $profilePhoto,
    'avatarFallback' => $profilePhoto,
  ])

  <div class="setup-main">
    <div class="card">
      @include('public.partials.steps', ['step' => 3])

      <h1>Fill in your contact card</h1>
      <p class="sub">{{ isset($fb['first_name']) ? 'Everything here is optional.' : 'A name or a company is enough.' }}</p>

      @if ($fb)
        <div class="from-profile">
          @if ($profilePhoto)<img src="{{ $profilePhoto }}" alt="">@endif
          <span>Anything you leave blank comes from your profile page, and changes when it does.</span>
        </div>
      @endif

      @if ($errors->any())
        <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif

      <form method="POST" action="{{ route('nfc.setup.contact', $card->public_id) }}" enctype="multipart/form-data">
        @csrf
        <label for="first_name">First name</label>
        <input id="first_name" name="first_name" type="text" maxlength="80" autofocus
               placeholder="{{ $fb['first_name'] ?? 'e.g. John' }}" data-fallback="{{ $fb['first_name'] ?? '' }}"
               value="{{ old('first_name', $contactProfile?->first_name) }}">

        <label for="last_name">Last name <span class="opt">(optional)</span></label>
        <input id="last_name" name="last_name" type="text" maxlength="80"
               placeholder="e.g. Doe" value="{{ old('last_name', $contactProfile?->last_name) }}">

        <label for="job_title">What you do <span class="opt">(optional)</span></label>
        <input id="job_title" name="job_title" type="text" maxlength="120"
               placeholder="{{ $fb['job_title'] ?? 'e.g. Owner' }}" data-fallback="{{ $fb['job_title'] ?? '' }}"
               value="{{ old('job_title', $contactProfile?->job_title) }}">

        <label for="company">Company or shop name <span class="opt">{{ isset($fb['first_name']) ? '(optional)' : '(optional if you gave a name)' }}</span></label>
        <input id="company" name="company" type="text" maxlength="150"
               placeholder="{{ $fb['company'] ?? 'e.g. Acme Inc.' }}" data-fallback="{{ $fb['company'] ?? '' }}"
               value="{{ old('company', $contactProfile?->company) }}">

        <label for="mobile_phone">Mobile number <span class="opt">(optional)</span></label>
        <input id="mobile_phone" name="mobile_phone" type="tel"
               placeholder="{{ $fb['mobile_phone'] ?? '+63 917 123 4567' }}" data-fallback="{{ $fb['mobile_phone'] ?? '' }}"
               value="{{ old('mobile_phone', $contactProfile?->mobile_phone) }}">

        <label for="personal_email">Email <span class="opt">(optional)</span></label>
        <input id="personal_email" name="personal_email" type="text" inputmode="email" maxlength="190"
               placeholder="{{ $fb['personal_email'] ?? 'e.g. john@example.com' }}" data-fallback="{{ $fb['personal_email'] ?? '' }}"
               value="{{ old('personal_email', $contactProfile?->personal_email) }}">

        <label for="website">Website <span class="opt">(optional)</span></label>
        <input id="website" name="website" type="url" inputmode="url" placeholder="https://…" maxlength="500"
               value="{{ old('website', $contactProfile?->website) }}">

        {{-- id="avatar" is what the preview listens to for a picked photo. --}}
        <label for="avatar">Your photo <span class="opt">(optional &mdash; JPG, PNG or WebP, up to 5 MB)</span></label>
        <input id="avatar" name="photo" type="file" accept="image/jpeg,image/png,image/webp" data-crop>
        @if ($ownPhoto)
          <img class="avatar-preview" src="{{ asset('storage/'.$ownPhoto) }}" alt="Your current photo">
          <label class="check"><input name="remove_photo" type="checkbox" value="1">
            Remove this photo{{ $profilePhoto ? ' (your profile photo is used instead)' : '' }}</label>
        @elseif ($profilePhoto)
          <p class="photo-note">Leave this empty to use your profile photo.</p>
        @endif

        <div class="actions">
          @include('public.partials.back-to-types')
          <button class="primary" type="submit">Save and go live</button>
        </div>
      </form>

      @include('public.partials.card-id')
    </div>
  </div>
</div>
@include('partials.photo-cropper')
@endsection

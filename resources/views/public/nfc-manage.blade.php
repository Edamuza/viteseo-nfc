@extends('layouts.public')
@section('title','My card')
@section('body')
<style>
  .status{background:#ecfdf3;border:1px solid #abefc6;color:#067647;padding:11px 13px;border-radius:9px;margin-bottom:18px}
  .sect{margin-top:26px;padding-top:18px;border-top:1px solid var(--line)}
  .sect h2{font-size:.95rem;margin:0 0 2px}
</style>
{{-- Deliberately sparse. This page used to open with its own title, then
     repeat the current mode and its description, then head a list that
     already carries a Current badge - three ways of saying one thing, above
     a paragraph promising nothing would be lost. The list says it all. --}}
<div class="card">
  @if (session('status'))<div class="status">{{ session('status') }}</div>@endif
  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  @if (! $unlocked)
    <div class="head">
      @include('public.partials.back-button', [
        'href' => route('nfc.show', $card->public_id), 'label' => 'Back to my card', 'iconOnly' => true,
      ])
      <h1>My card</h1>
      <p class="sub">Enter your PIN to change your card.</p>
    </div>
    <form method="POST" action="{{ route('nfc.manage.unlock', $card->public_id) }}">
      @csrf
      <label for="activation_pin">{{ $card->pinIsPersonalized() ? 'Your card PIN' : 'Activation PIN' }}</label>
      <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
             maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required autofocus>
      @include('public.partials.new-pin')
      <button class="primary" type="submit">Continue</button>
    </form>
  @else
    <div class="head">
      @include('public.partials.back-button', [
        'href' => route('nfc.show', $card->public_id), 'label' => 'Back to my card', 'iconOnly' => true,
      ])
      <h1>What your card does</h1>
    </div>
    @foreach (\App\Enums\NfcMode::selfSetup() as $mode)
      <div class="choice {{ $card->nfcMode() === $mode ? 'now' : '' }}">
        <a class="choice-go"
           href="{{ $mode === \App\Enums\NfcMode::Profile
                ? route('nfc.profile.edit', $card->public_id)
                : route('nfc.setup', [$card->public_id, $mode->value]) }}">
          <span>
            <span class="ct">{{ $mode->label() }}</span>
            <span class="cd">{{ $mode->description() }}</span>
          </span>
          @if ($card->nfcMode() === $mode)
            <span class="now-badge">Current</span>
          @else
            <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
          @endif
        </a>
        <button class="eye" type="button" data-preview="{{ $mode->value }}"
                aria-label="Preview {{ $mode->label() }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
          Preview
        </button>
      </div>
    @endforeach

    <div class="sect">
      <h2>Change PIN</h2>
      <form method="POST" action="{{ route('nfc.manage.pin', $card->public_id) }}">
        @csrf
        <label for="new_pin">New 6-digit PIN</label>
        <input class="pin" id="new_pin" name="new_pin" type="tel" inputmode="numeric"
               maxlength="6" autocomplete="new-password" placeholder="New PIN" required>
        <label for="new_pin_confirmation">Repeat new PIN</label>
        <input class="pin" id="new_pin_confirmation" name="new_pin_confirmation" type="tel" inputmode="numeric"
               maxlength="6" autocomplete="new-password" placeholder="Repeat new PIN" required>
        <button class="primary" type="submit">Change PIN</button>
      </form>
    </div>

  @endif

  @include('public.partials.card-id')
</div>
@if ($unlocked)
  @include('public.partials.preview-dialogs')
@endif
@endsection

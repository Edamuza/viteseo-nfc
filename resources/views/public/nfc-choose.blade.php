@extends('layouts.public')
@section('title','Set up this card')
@section('body')
<div class="card">
  @include('public.partials.steps', ['step' => 1])

  <h1>What should your card do?</h1>
  <p class="sub" style="margin-bottom:10px">
    Pick what the other person sees when they tap your card. Not sure? Tap
    <strong>Preview</strong> to see an example first. You can change your mind later.
  </p>

  @foreach (\App\Enums\NfcMode::selfSetup() as $mode)
    <div class="choice">
      <a class="choice-go"
         href="{{ $mode === \App\Enums\NfcMode::Profile
              ? route('nfc.profile.edit', $card->public_id)
              : route('nfc.setup', [$card->public_id, $mode->value]) }}">
        <span>
          <span class="ct">{{ $mode->label() }}</span>
          <span class="cd">{{ $mode->description() }}</span>
        </span>
        <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
      </a>
      <button class="eye" type="button" data-preview="{{ $mode->value }}"
              aria-label="Preview {{ $mode->label() }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
        Preview
      </button>
    </div>
  @endforeach

  @include('public.partials.card-id')
</div>
@include('public.partials.preview-dialogs')
@endsection

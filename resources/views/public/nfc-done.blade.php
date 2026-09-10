@extends('layouts.public')
@section('title','Card is live')
@section('body')
{{-- The last screen of setup, and until now a dead end: it congratulated the
     owner and offered them nothing to do next. Both things they want are one
     tap away here - see the card the way a stranger will, and change it. --}}
<div class="card">
  <div class="ok" aria-hidden="true">&#10003;</div>
  <h1>Your card is live</h1>

  {{-- A contact card has no page of its own any more - a tap opens the
       phone's contact screen - so the "Card owner?" link that used to sit on
       that page is gone. This is where the owner learns the way back in. --}}
  @if ($card->nfcMode() === \App\Enums\NfcMode::Contact)
    <p class="sub">A tap now opens the save-contact screen on the other person's phone.
      To change your card later, open this address &mdash; worth bookmarking:<br>
      <a href="{{ route('nfc.manage', $card->public_id) }}">{{ route('nfc.manage', $card->public_id) }}</a></p>
  @endif

  <div class="actions">
    <a class="btn-2" href="{{ route('nfc.manage', $card->public_id) }}">Change</a>
    <a class="primary" href="{{ route('nfc.show', $card->public_id) }}">See my card</a>
  </div>

  @include('public.partials.card-id')
</div>
@endsection

@extends('layouts.public')
@section('title','Card is live')
@section('body')
{{-- The last screen of setup, and until now a dead end: it congratulated the
     owner and offered them nothing to do next. Both things they want are one
     tap away here - see the card the way a stranger will, and change it. --}}
<div class="card">
  <div class="ok" aria-hidden="true">&#10003;</div>
  <h1 style="text-align:center">Your card is live</h1>
  <p class="sub" style="text-align:center">
    Tap your card on any phone and this is what shows up.
  </p>

  <a class="primary" href="{{ route('nfc.show', $card->public_id) }}">See my card</a>

  <p style="text-align:center;margin:16px 0 0;font-size:.88rem">
    <a href="{{ route('nfc.manage', $card->public_id) }}">Change what my card does</a>
  </p>

  @include('public.partials.card-id')
</div>
@endsection

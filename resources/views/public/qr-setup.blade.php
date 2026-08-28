@extends('layouts.public')
@section('title','Set up this QR code')
@section('body')
<div class="card">
  <h1>Set up your QR code</h1>
  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('qr.store', $card->public_id) }}">
    @csrf
    {{-- The PIN is verified against the card, and the card's PIN changes the
         first time its owner sets up the NFC side. Calling it the "activation
         PIN" after that point sends them hunting for the printed number that
         no longer works. --}}
    <label for="activation_pin">{{ $card->pinIsPersonalized() ? 'Your card PIN' : 'Activation PIN' }}</label>
    <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
           maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>

    <label for="destination">Where should the QR code go?</label>
    <input id="destination" name="destination" type="url" inputmode="url"
           placeholder="https://example.com" value="{{ old('destination') }}" required>

    <button class="primary" type="submit">Save and go live</button>
  </form>

  @include('public.partials.card-id')
</div>
@endsection

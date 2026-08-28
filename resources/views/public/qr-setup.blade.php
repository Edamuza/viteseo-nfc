@extends('layouts.public')
@section('title','Set up this QR code')
@section('body')
<div class="card">
  <h1>Set up your QR code</h1>
  <p class="sub">
    The QR code printed on your card opens one web address. Tell us which one, and
    anyone who scans the card goes straight there.
  </p>

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
    <p class="pin-hint">
      @if ($card->pinIsPersonalized())
        The PIN you chose when you set up this card.
      @else
        This is the number printed on the sheet that came with your card.
      @endif
    </p>

    <label for="destination">Where should the QR code go?</label>
    <input id="destination" name="destination" type="url" inputmode="url"
           placeholder="https://example.com" value="{{ old('destination') }}" required>

    <button class="primary" type="submit">Save and go live</button>
  </form>

  @include('public.partials.card-id')
</div>
@endsection

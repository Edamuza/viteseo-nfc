@extends('layouts.public')
@section('title','Set up this card — Phone')
@section('body')
<div class="card">
  <h1>Phone / Call</h1>
  <p class="sub">Tapping your card will show a Call Now button for this number.</p>

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('nfc.setup.phone', $card->public_id) }}">
    @csrf
    <label for="activation_pin">Activation PIN</label>
    <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
           maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>

    <label for="nfc_phone_number">Phone number</label>
    <input id="nfc_phone_number" name="nfc_phone_number" type="tel" placeholder="+63 917 123 4567"
           value="{{ old('nfc_phone_number') }}" required>

    <button class="primary" type="submit">Save my card</button>
  </form>

  <p style="text-align:center;margin:18px 0 0;font-size:.88rem">
    <a href="{{ route('nfc.show', $card->public_id) }}">&larr; Choose a different card type</a>
  </p>
</div>
@endsection

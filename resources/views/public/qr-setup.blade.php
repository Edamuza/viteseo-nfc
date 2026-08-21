@extends('layouts.public')
@section('title','Set up this QR code')
@section('body')
<div class="card">
  <h1>Set up your QR code</h1>
  <p class="sub">This card is not linked yet. Enter your activation PIN and the web address this QR code should open.</p>

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('qr.store', $card->public_id) }}">
    @csrf
    <label for="activation_pin">Activation PIN</label>
    <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
           maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>

    <label for="destination">Destination web address</label>
    <input id="destination" name="destination" type="url" inputmode="url"
           placeholder="https://example.com" value="{{ old('destination') }}" required>

    <button class="primary" type="submit">Save destination</button>
  </form>
</div>
@endsection

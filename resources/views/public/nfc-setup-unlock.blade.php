@extends('layouts.public')
@section('title','Unlock your card')
@section('body')
{{-- Step 2 of 3. The PIN is the only question on this screen on purpose: the
     details screen that follows should be about the card, not about security. --}}
<div class="card">
  @include('public.partials.steps', ['step' => 2])

  <h1>Unlock your card</h1>
  <p class="sub">You picked <strong>{{ $mode->label() }}</strong>. Enter your card PIN to continue.</p>

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('nfc.setup.unlock', [$card->public_id, $mode->value]) }}">
    @csrf
    <label for="activation_pin">{{ $card->pinIsPersonalized() ? 'Your card PIN' : 'Activation PIN' }}</label>
    <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
           maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required autofocus>
    @unless ($card->pinIsPersonalized())
      <p class="pin-hint">This is the number printed on the sheet that came with your card.</p>
    @endunless
    @include('public.partials.new-pin')
    <button class="primary" type="submit">Continue</button>
  </form>

  @include('public.partials.back-to-types')
  @include('public.partials.card-id')
</div>
@endsection

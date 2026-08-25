@extends('layouts.public')
@section('title','Set up this card — Contact Card')
@section('body')
<div class="card">
  <h1>Digital Contact Card</h1>
  <p class="sub">Tapping your card will show your details with a Save to Contacts button. Fill in what you want to share &mdash; at least a name or a company.</p>

  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('nfc.setup.contact', $card->public_id) }}">
    @csrf
    <label for="activation_pin">{{ $card->pinIsPersonalized() ? 'Your card PIN' : 'Activation PIN' }}</label>
    <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
           maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>
    @include('public.partials.new-pin')

    <label for="first_name">First name</label>
    <input id="first_name" name="first_name" type="text" maxlength="80" value="{{ old('first_name', $contactProfile?->first_name) }}">

    <label for="last_name">Last name <span class="opt">(optional)</span></label>
    <input id="last_name" name="last_name" type="text" maxlength="80" value="{{ old('last_name', $contactProfile?->last_name) }}">

    <label for="job_title">Job title <span class="opt">(optional)</span></label>
    <input id="job_title" name="job_title" type="text" maxlength="120" value="{{ old('job_title', $contactProfile?->job_title) }}">

    <label for="company">Company <span class="opt">(optional if you gave a name)</span></label>
    <input id="company" name="company" type="text" maxlength="150" value="{{ old('company', $contactProfile?->company) }}">

    <label for="mobile_phone">Mobile number <span class="opt">(optional)</span></label>
    <input id="mobile_phone" name="mobile_phone" type="tel" value="{{ old('mobile_phone', $contactProfile?->mobile_phone) }}">

    <label for="personal_email">Email <span class="opt">(optional)</span></label>
    <input id="personal_email" name="personal_email" type="text" inputmode="email" maxlength="190" value="{{ old('personal_email', $contactProfile?->personal_email) }}">

    <label for="website">Website <span class="opt">(optional)</span></label>
    <input id="website" name="website" type="url" inputmode="url" placeholder="https://…" maxlength="500" value="{{ old('website', $contactProfile?->website) }}">

    <button class="primary" type="submit">Save my card</button>
  </form>

  <p style="text-align:center;margin:18px 0 0;font-size:.88rem">
    <a href="{{ route('nfc.show', $card->public_id) }}">&larr; Choose a different card type</a>
  </p>
  @include('public.partials.card-id')
</div>
@endsection

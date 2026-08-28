@extends('layouts.public')
@section('title','Add your phone number')
@section('wrapclass','wide')
@section('body')
<div class="setup">
  @include('public.partials.preview-live', ['kind' => 'phone'])

  <div class="setup-main">
    <div class="card">
      @include('public.partials.steps', ['step' => 3])

      <h1>Add your phone number</h1>
      <p class="sub">Taps show one Call button.</p>

      @if ($errors->any())
        <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif

      <form method="POST" action="{{ route('nfc.setup.phone', $card->public_id) }}">
        @csrf
        <label for="nfc_phone_number">Phone number</label>
        <input id="nfc_phone_number" name="nfc_phone_number" type="tel" placeholder="+63 917 123 4567"
               value="{{ old('nfc_phone_number', $card->nfc_phone_number) }}" required autofocus>

        <button class="primary" type="submit">Save and go live</button>
      </form>

      @include('public.partials.back-to-types')
      @include('public.partials.card-id')
    </div>
  </div>
</div>
@endsection

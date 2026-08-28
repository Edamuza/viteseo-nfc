@extends('layouts.public')
@section('title','Fill in your contact card')
@section('wrapclass','wide')
@section('body')
<div class="setup">
  @include('public.partials.preview-live', ['kind' => 'contact'])

  <div class="setup-main">
    <div class="card">
      @include('public.partials.steps', ['step' => 3])

      <h1>Fill in your contact card</h1>
      <p class="sub">A name or a company is enough.</p>

      @if ($errors->any())
        <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif

      <form method="POST" action="{{ route('nfc.setup.contact', $card->public_id) }}">
        @csrf
        <label for="first_name">First name</label>
        <input id="first_name" name="first_name" type="text" maxlength="80" autofocus
               placeholder="e.g. John" value="{{ old('first_name', $contactProfile?->first_name) }}">

        <label for="last_name">Last name <span class="opt">(optional)</span></label>
        <input id="last_name" name="last_name" type="text" maxlength="80"
               placeholder="e.g. Doe" value="{{ old('last_name', $contactProfile?->last_name) }}">

        <label for="job_title">What you do <span class="opt">(optional)</span></label>
        <input id="job_title" name="job_title" type="text" maxlength="120"
               placeholder="e.g. Owner" value="{{ old('job_title', $contactProfile?->job_title) }}">

        <label for="company">Company or shop name <span class="opt">(optional if you gave a name)</span></label>
        <input id="company" name="company" type="text" maxlength="150"
               placeholder="e.g. Acme Inc." value="{{ old('company', $contactProfile?->company) }}">

        <label for="mobile_phone">Mobile number <span class="opt">(optional)</span></label>
        <input id="mobile_phone" name="mobile_phone" type="tel" placeholder="+63 917 123 4567"
               value="{{ old('mobile_phone', $contactProfile?->mobile_phone) }}">

        <label for="personal_email">Email <span class="opt">(optional)</span></label>
        <input id="personal_email" name="personal_email" type="text" inputmode="email" maxlength="190"
               placeholder="e.g. john@example.com" value="{{ old('personal_email', $contactProfile?->personal_email) }}">

        <label for="website">Website <span class="opt">(optional)</span></label>
        <input id="website" name="website" type="url" inputmode="url" placeholder="https://…" maxlength="500"
               value="{{ old('website', $contactProfile?->website) }}">

        <div class="actions">
          @include('public.partials.back-to-types')
          <button class="primary" type="submit">Save and go live</button>
        </div>
      </form>

      @include('public.partials.card-id')
    </div>
  </div>
</div>
@endsection

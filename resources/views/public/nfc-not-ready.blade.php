@extends('layouts.public')
@section('title','Not set up yet')
@section('body')
<div class="card" style="text-align:center">
  <h1>Not set up yet</h1>

  @if ($card->nfcMode() === \App\Enums\NfcMode::Profile)
    {{-- Profile mode has a PIN-protected self-service editor, and update()
         creates the profile row when none exists — so an empty Profile card
         is the card owner's starting point, not a dead end. Without this
         link, a customer handed a fresh Profile-mode card had no way to add
         their own details and assumed only the admin could. --}}
    <p class="sub">This card doesn&rsquo;t have profile details yet.</p>
    <p class="sub" style="margin-bottom:6px">
      <strong>Is this your card?</strong> Set it up yourself using the activation PIN that came with it.
    </p>
    <a class="primary" href="{{ route('nfc.profile.edit', $card->public_id) }}">Set up my profile</a>
  @else
    <p class="sub" style="margin:0">
      This card has not been configured. Please check back shortly, or contact whoever gave you the card.
    </p>
  @endif
</div>
@endsection

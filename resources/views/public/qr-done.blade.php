@extends('layouts.public')
@section('title','QR code is live')
@section('body')
<div class="card">
  <div class="ok" aria-hidden="true">&#10003;</div>
  <h1 style="text-align:center">Your QR code is live</h1>
  <p class="sub" style="text-align:center">Anyone scanning this card now goes straight to your link.</p>
  @if ($card->qrIsConfigured())
    <p style="text-align:center"><code>{{ $card->qr_destination }}</code></p>
  @endif
</div>
@endsection

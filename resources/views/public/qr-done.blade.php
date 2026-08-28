@extends('layouts.public')
@section('title','QR code is live')
@section('body')
<div class="card">
  <div class="ok" aria-hidden="true">&#10003;</div>
  <h1 style="text-align:center">Your QR code is live</h1>
  @if ($card->qrIsConfigured())
    <p style="text-align:center"><code>{{ $card->qr_destination }}</code></p>
  @endif
  @include('public.partials.card-id')
</div>
@endsection

@extends('layouts.public')
@section('title','Call')
@section('body')
<div class="card" style="text-align:center">
  <p class="sub" style="margin:0 0 6px;text-transform:uppercase;letter-spacing:.08em;font-size:.72rem">Call</p>
  <h1 style="font-size:1.5rem;margin:0 0 22px;overflow-wrap:anywhere">{{ $display }}</h1>
  <a class="primary" style="margin-top:0" href="tel:{{ $dialable }}">Call Now</a>
  <p class="foot" style="margin-top:18px"><a href="{{ route('nfc.manage', $card->public_id) }}" style="color:var(--muted);font-weight:500">Card owner? Manage this card</a></p>
</div>
@endsection

@extends('layouts.public')
@section('title','Send Email')
@section('body')
<div class="card" style="text-align:center">
  <p class="sub" style="margin:0 0 6px;text-transform:uppercase;letter-spacing:.08em;font-size:.72rem">Email</p>
  <h1 style="font-size:1.25rem;margin:0 0 6px;overflow-wrap:anywhere">{{ $card->nfc_email_address }}</h1>
  @if ($card->nfc_email_subject)
    <p class="sub" style="margin:0 0 20px">Subject: {{ $card->nfc_email_subject }}</p>
  @else
    <div style="height:16px"></div>
  @endif
  <a class="primary" style="margin-top:0" href="{{ $mailto }}">Send Email</a>
</div>
@endsection

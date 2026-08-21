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
  <a href="{{ $mailto }}"
     style="display:flex;align-items:center;justify-content:center;min-height:52px;padding:14px;
            background:var(--accent);color:#fff;border-radius:10px;text-decoration:none;
            font-weight:580;font-size:1rem">Send Email</a>
</div>
@endsection

@extends('layouts.public')
@section('title','Call')
@section('body')
<div class="card" style="text-align:center">
  <p class="sub" style="margin:0 0 6px;text-transform:uppercase;letter-spacing:.08em;font-size:.72rem">Call</p>
  <h1 style="font-size:1.5rem;margin:0 0 22px;overflow-wrap:anywhere">{{ $display }}</h1>
  <a href="tel:{{ $dialable }}"
     style="display:flex;align-items:center;justify-content:center;min-height:52px;padding:14px;
            background:var(--accent);color:#fff;border-radius:10px;text-decoration:none;
            font-weight:580;font-size:1rem">Call Now</a>
</div>
@endsection

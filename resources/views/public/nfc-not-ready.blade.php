@extends('layouts.public')
@section('title','Not set up yet')
@section('body')
{{-- Only ever reached by an empty card in a hidden mode: every mode a customer
     can choose routes to the setup chooser instead. It used to carry a second
     branch offering Profile-mode self-setup, which became unreachable the day
     the chooser started handling that case. --}}
<div class="card">
  <h1>Not set up yet</h1>
  <p class="sub" style="margin:0">Please try again shortly, or ask whoever gave you the card.</p>
</div>
@endsection

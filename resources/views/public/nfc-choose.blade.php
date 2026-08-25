@extends('layouts.public')
@section('title','Set up this card')
@section('body')
<style>
  .choice{
    display:flex;align-items:center;gap:14px;width:100%;
    padding:16px;margin-top:10px;border:1px solid var(--line-strong);border-radius:12px;
    background:var(--surface);color:var(--text);text-decoration:none;font-weight:500;
    transition:border-color .12s ease,background .12s ease;
  }
  .choice:hover{border-color:var(--accent);background:var(--accent-soft);text-decoration:none}
  .choice:active{transform:scale(.995)}
  .choice .ct{display:block;font-weight:600;font-size:.98rem;margin-bottom:2px}
  .choice .cd{display:block;color:var(--muted);font-size:.84rem;line-height:1.4}
  .choice .arrow{margin-left:auto;color:var(--muted);flex:0 0 auto}
</style>
<div class="card">
  <h1>Set up your card</h1>
  <p class="sub" style="margin-bottom:10px">
    This card isn&rsquo;t configured yet. Choose what it should do when someone taps it &mdash;
    you&rsquo;ll need the activation PIN that came with the card.
  </p>

  @foreach (\App\Enums\NfcMode::selfSetup() as $mode)
    <a class="choice"
       href="{{ $mode === \App\Enums\NfcMode::Profile
            ? route('nfc.profile.edit', $card->public_id)
            : route('nfc.setup', [$card->public_id, $mode->value]) }}">
      <span>
        <span class="ct">{{ $mode->label() }}</span>
        <span class="cd">{{ $mode->description() }}</span>
      </span>
      <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
    </a>
  @endforeach
</div>
@endsection

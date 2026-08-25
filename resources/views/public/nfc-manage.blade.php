@extends('layouts.public')
@section('title','Manage this card')
@section('body')
<style>
  .choice{
    display:flex;align-items:center;gap:14px;width:100%;
    padding:15px;margin-top:10px;border:1px solid var(--line-strong);border-radius:12px;
    background:var(--surface);color:var(--text);text-decoration:none;font-weight:500;
    transition:border-color .12s ease,background .12s ease;
  }
  .choice:hover{border-color:var(--accent);background:var(--accent-soft);text-decoration:none}
  .choice .ct{display:block;font-weight:600;font-size:.96rem;margin-bottom:2px}
  .choice .cd{display:block;color:var(--muted);font-size:.83rem;line-height:1.4}
  .choice .arrow{margin-left:auto;color:var(--muted);flex:0 0 auto}
  .choice.now{border-color:var(--accent)}
  .now-badge{
    margin-left:auto;flex:0 0 auto;font-size:.72rem;font-weight:600;color:var(--accent);
    background:var(--accent-soft);border:1px solid var(--accent);border-radius:999px;padding:3px 10px;
  }
  .status{background:#ecfdf3;border:1px solid #abefc6;color:#067647;padding:11px 13px;border-radius:9px;margin-bottom:18px}
  .sect{margin-top:26px;padding-top:18px;border-top:1px solid var(--line)}
  .sect h2{font-size:1rem;margin:0 0 4px}
</style>
<div class="card">
  <h1>Manage this card</h1>

  @if (session('status'))<div class="status">{{ session('status') }}</div>@endif
  @if ($errors->any())
    <div class="err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  @if (! $unlocked)
    <p class="sub">Enter your card PIN to change what this card does or to change the PIN itself.</p>
    <form method="POST" action="{{ route('nfc.manage.unlock', $card->public_id) }}">
      @csrf
      <label for="activation_pin">{{ $card->pinIsPersonalized() ? 'Your card PIN' : 'Activation PIN' }}</label>
      <input class="pin" id="activation_pin" name="activation_pin" type="tel" inputmode="numeric"
             maxlength="6" autocomplete="one-time-code" placeholder="6-digit PIN" required>
      @include('public.partials.new-pin')
      <button class="primary" type="submit">Continue</button>
    </form>
  @else
    <p class="sub" style="margin-bottom:4px">What your card does now:</p>
    <p style="margin:0 0 6px"><strong>{{ $card->nfcMode()->label() }}</strong>
      <span class="sub" style="display:inline">&mdash; {{ $card->nfcMode()->description() }}</span></p>

    <div class="sect">
      <h2>Change what this card does</h2>
      <p class="sub">Your saved details are kept for every type &mdash; switching back later restores them.</p>
      @foreach (\App\Enums\NfcMode::selfSetup() as $mode)
        <a class="choice {{ $card->nfcMode() === $mode ? 'now' : '' }}"
           href="{{ $mode === \App\Enums\NfcMode::Profile
                ? route('nfc.profile.edit', $card->public_id)
                : route('nfc.setup', [$card->public_id, $mode->value]) }}">
          <span>
            <span class="ct">{{ $mode->label() }}</span>
            <span class="cd">{{ $mode->description() }}</span>
          </span>
          @if ($card->nfcMode() === $mode)
            <span class="now-badge">Current</span>
          @else
            <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
          @endif
        </a>
      @endforeach
    </div>

    <div class="sect">
      <h2>Change your PIN</h2>
      <form method="POST" action="{{ route('nfc.manage.pin', $card->public_id) }}">
        @csrf
        <label for="new_pin">New 6-digit PIN</label>
        <input class="pin" id="new_pin" name="new_pin" type="tel" inputmode="numeric"
               maxlength="6" autocomplete="new-password" placeholder="New PIN" required>
        <label for="new_pin_confirmation">Repeat new PIN</label>
        <input class="pin" id="new_pin_confirmation" name="new_pin_confirmation" type="tel" inputmode="numeric"
               maxlength="6" autocomplete="new-password" placeholder="Repeat new PIN" required>
        <button class="primary" type="submit">Change PIN</button>
      </form>
    </div>

    <p style="text-align:center;margin:20px 0 0;font-size:.88rem">
      <a href="{{ route('nfc.show', $card->public_id) }}">&larr; Back to my card</a>
    </p>
  @endif

  @include('public.partials.card-id')
</div>
@endsection

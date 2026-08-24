@extends('layouts.admin')
@section('title','Cards')
@section('body')

<div class="page-head">
  <h1>NFC Card Management</h1>
  <p>Each card has two permanent URLs that never change. QR takes one destination; NFC takes several.</p>
</div>

<div class="stats">
  <div class="stat"><div class="n">{{ $stats['total'] }}</div><div class="l">Total Cards</div></div>
  <div class="stat"><div class="n">{{ $stats['fullyUnconfigured'] }}</div><div class="l">Unconfigured</div></div>
  <div class="stat"><div class="n">{{ $stats['qrConfigured'] }}</div><div class="l">QR Configured</div></div>
  <div class="stat"><div class="n">{{ $stats['nfcConfigured'] }}</div><div class="l">NFC Configured</div></div>
  <div class="stat"><div class="n">{{ $stats['fullyConfigured'] }}</div><div class="l">Fully Configured</div></div>
</div>

<div class="panel panel-tight">
  <div class="row" style="justify-content:space-between">
    <div class="row row-tight">
      <span class="small muted" style="margin-right:4px">Generate</span>
      @foreach ([5,10,25] as $n)
        <form method="POST" action="{{ route('admin.cards.generate') }}">@csrf
          <input type="hidden" name="quantity" value="{{ $n }}">
          <button class="btn btn-sm" type="submit">+{{ $n }}</button>
        </form>
      @endforeach
      <form method="POST" action="{{ route('admin.cards.generate') }}" class="row row-tight">@csrf
        <input type="number" name="quantity" min="1" max="{{ \App\Services\CardGenerationService::MAX_PER_REQUEST }}"
               placeholder="Custom" style="width:92px" aria-label="Custom quantity" required>
        <button class="btn btn-sm btn-p" type="submit">Create</button>
      </form>
    </div>

    <div class="row row-tight">
      <form method="POST" action="{{ route('admin.export.qr-zip') }}">@csrf
        <input type="hidden" name="format" value="svg">
        <button class="btn btn-sm" type="submit">Download QR codes (SVG)</button>
      </form>
      <form method="POST" action="{{ route('admin.export.sticker-templates') }}">@csrf
        <button class="btn btn-sm" type="submit">Download all templates (PNG)</button>
      </form>
      <form method="POST" action="{{ route('admin.export.activation') }}"
            onsubmit="return confirm('This CSV lists the activation PIN for every card. Continue?')">@csrf
        <button class="btn btn-sm" type="submit">Activation manifest</button>
      </form>
    </div>
  </div>
</div>

<div class="panel">
  <div class="row" style="justify-content:space-between;margin-bottom:14px">
    <h2 style="margin:0">Cards</h2>
    <form method="GET" action="{{ route('admin.dashboard') }}" class="row row-tight">
      <input type="text" name="search" value="{{ $search }}" placeholder="Card number or public ID"
             style="min-width:220px" aria-label="Search cards">
      <button class="btn btn-sm" type="submit">Search</button>
      @if ($search !== '')<a class="btn btn-sm btn-ghost" href="{{ route('admin.dashboard') }}">Clear</a>@endif
    </form>
  </div>

  <div class="tablewrap">
  <table>
    <thead><tr>
      <th>Card Number</th><th>Public ID</th><th>QR Status</th><th>NFC Status</th>
      <th>NFC Links</th><th>Created</th><th></th>
    </tr></thead>
    <tbody>
    @forelse ($cards as $card)
      @php
        $n        = (int) $card->active_link_count;
        $mode     = $card->nfcMode();
        $nfcOn    = $card->nfcModeIsConfigured();
        $hasLinks = $mode->publishesLinks();
        $qrOn     = $card->qrIsConfigured();
      @endphp
      <tr>
        <td data-label="Card Number"><strong>{{ $card->card_number }}</strong></td>
        <td data-label="Public ID"><code>{{ $card->public_id }}</code></td>
        {{-- qrIsConfigured(), not raw truthiness: the counters above use its SQL
             twin, and a destination of "0" would otherwise read as Unconfigured
             here while counting as configured up there. --}}
        <td data-label="QR Status">
          <span class="badge {{ $qrOn ? 'b-ok' : 'b-off' }}">
            {{ $qrOn ? 'Active' : 'Unconfigured' }}
          </span>
        </td>
        {{-- Badge text stays on ONE line: the tests assert the whole
             "label &middot; state" string, and Blade would otherwise wrap
             newlines and indentation into the middle of it. --}}
        <td data-label="NFC Status">
          <span class="badge {{ $nfcOn ? 'b-ok' : 'b-off' }}">{{ $mode->label() }} &middot; {{ $nfcOn ? 'Active' : 'Unconfigured' }}</span>
        </td>
        {{-- The count only means something where the mode actually publishes
             those links; showing 0 next to a live contact card is what made the
             old dashboard misleading, and an em dash next to a Profile card
             serving two of them was the same mistake inverted. Each mode's
             answer lives on the enum, next to its label and description. --}}
        <td data-label="NFC Links" class="mono muted">{{ $hasLinks ? $n : '—' }}</td>
        <td data-label="Created" class="muted small">{{ $card->created_at->format('Y-m-d') }}</td>
        <td><a class="btn btn-sm" href="{{ route('admin.cards.show', $card->public_id) }}">View</a></td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted" style="padding:28px;text-align:center">No cards match that search.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>

  <div class="pager">{{ $cards->links() }}</div>
</div>
@endsection

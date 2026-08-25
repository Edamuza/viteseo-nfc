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
  <div class="toolbar">
    <div class="tool-group">
      <span class="tool-label">Generate cards</span>
      <form method="POST" action="{{ route('admin.cards.generate') }}" class="row row-tight">@csrf
        <input type="number" name="quantity" min="1" max="{{ \App\Services\CardGenerationService::MAX_PER_REQUEST }}"
               placeholder="How many?" style="width:110px" aria-label="Number of cards to generate" required>
        <button class="btn btn-p" type="submit">Create</button>
      </form>
    </div>

    <div class="tool-group">
      <span class="tool-label">Export</span>
      <div class="row row-tight">
        {{-- The QR codes (SVG) export is hidden for now by product decision;
             the route and controller stay. Restore this form to bring it back:
             POST admin.export.qr-zip with hidden format=svg. --}}
        <button class="btn" type="button" id="tplexport-open">Download all templates (PNG)</button>
        <form method="POST" action="{{ route('admin.export.activation') }}"
              onsubmit="return confirm('This CSV lists the activation PIN for every card. Continue?')">@csrf
          <button class="btn" type="submit">Activation manifest</button>
        </form>
      </div>
    </div>
  </div>
</div>

{{-- The export details live in this modal, not in the toolbar: the card range
     and template choice only matter at the moment of downloading, and here the
     templates can be picked visually rather than from a blind name dropdown. --}}
<dialog id="tplexport" class="exp-modal">
  <div class="exp-head">
    <strong>Download sticker templates (PNG)</strong>
    <button class="btn btn-sm" type="button" id="tplexport-close" aria-label="Close">Close &times;</button>
  </div>

  <form method="POST" action="{{ route('admin.export.sticker-templates') }}">@csrf
    <label class="fld">Card number range <span class="muted" style="font-weight:400">&mdash; up to 200 cards per download</span></label>
    <div class="row row-tight" style="margin-bottom:16px">
      <span class="small muted">CARD-</span>
      <input type="number" name="from" min="1" value="{{ $stickerFrom }}" aria-label="From card number" style="width:90px" required>
      <span class="small muted">to CARD-</span>
      <input type="number" name="to" min="1" value="{{ $stickerTo }}" aria-label="To card number" style="width:90px" required>
    </div>

    <label class="fld">Template to print</label>
    <div class="picktpl-grid">
      @foreach ($stickerTemplates as $template)
        <label class="picktpl">
          <input type="radio" name="template_id" value="{{ $template->id }}" @checked($template->is_active) required>
          <span class="pt-thumbs">
            <img src="{{ asset('storage/'.$template->front_path) }}" alt="" loading="lazy">
            <img src="{{ asset('storage/'.$template->back_path) }}" alt="" loading="lazy">
          </span>
          <span class="pt-name">{{ $template->name }}
            @if ($template->is_active)<span class="badge b-ok">Active</span>@endif
          </span>
        </label>
      @endforeach
    </div>

    <div class="row" style="margin-top:16px;justify-content:flex-end">
      <button class="btn btn-p" type="submit">Download ZIP</button>
    </div>
  </form>
</dialog>

<style>
.exp-modal{
  border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;
  width:min(640px,94vw);max-height:90vh;box-shadow:0 18px 50px rgba(16,24,40,.25);
}
.exp-modal::backdrop{background:rgba(16,24,40,.55)}
.exp-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
.picktpl-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));max-height:46vh;overflow-y:auto;padding:2px}
.picktpl{
  display:block;border:1px solid var(--line-strong);border-radius:10px;padding:10px;
  cursor:pointer;background:var(--surface);transition:border-color .12s ease,background .12s ease;
}
.picktpl:hover{border-color:var(--muted)}
.picktpl:has(input:checked){border-color:var(--accent);background:var(--accent-soft)}
.picktpl input{position:absolute;opacity:0;pointer-events:none}
.pt-thumbs{display:flex;gap:6px;margin-bottom:8px}
.pt-thumbs img{width:calc(50% - 3px);height:auto;border:1px solid var(--line);border-radius:6px;display:block;background:var(--surface)}
.pt-name{font-size:.85rem;font-weight:600;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
</style>

<script>
(function () {
  var dlg = document.getElementById('tplexport');
  document.getElementById('tplexport-open').addEventListener('click', function () { dlg.showModal(); });
  document.getElementById('tplexport-close').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
})();
</script>

<div class="panel">
  @php
    $filtered = $search !== '' || $status !== 'all' || $sort !== 'newest';
    $D = \App\Http\Controllers\Admin\DashboardController::class;
  @endphp
  <div class="row" style="justify-content:space-between;margin-bottom:14px">
    <h2 style="margin:0">Cards</h2>
    <form method="GET" action="{{ route('admin.dashboard') }}" class="row row-tight">
      <input type="search" name="search" value="{{ $search }}" placeholder="Card number or public ID"
             style="min-width:200px" aria-label="Search cards">
      <select name="status" aria-label="Filter by status">
        @foreach ($D::STATUS_FILTERS as $value => $label)
          <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
        @endforeach
      </select>
      <select name="sort" aria-label="Sort cards">
        @foreach ($D::SORTS as $value => $label)
          <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
        @endforeach
      </select>
      <button class="btn btn-sm" type="submit">Apply</button>
      @if ($filtered)<a class="btn btn-sm btn-ghost" href="{{ route('admin.dashboard') }}">Reset</a>@endif
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
      <tr><td colspan="7" class="muted" style="padding:28px;text-align:center">No cards match that search or filter.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>

  <div class="pager">{{ $cards->links() }}</div>
</div>
@endsection

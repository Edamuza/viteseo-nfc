@extends('layouts.admin')
@section('title','Sticker templates')
@section('body')

<div class="page-head">
  <h1>Sticker templates</h1>
  <p>Each design is a front and back pair. The back needs a fully transparent hole; that is where the card QR is printed.</p>
</div>

<section class="panel">
  <h2>Add a template</h2>
  <form method="POST" action="{{ route('admin.sticker-templates.store') }}" enctype="multipart/form-data">
    @csrf
    <label class="fld" for="template-name">Name</label>
    <input id="template-name" type="text" name="name" maxlength="80" value="{{ old('name') }}" required style="width:100%;max-width:420px">
    <div class="fgrid" style="margin-top:14px">
      <label><span>Front PNG</span><input type="file" name="front" accept="image/png" required></label>
      <label><span>Back PNG (transparent QR hole)</span><input type="file" name="back" accept="image/png" required></label>
    </div>
    <button class="btn btn-p" type="submit">Save template</button>
  </form>
</section>

{{-- One library container instead of a panel per template: templates are
     compared and chosen, so they belong side by side. Small tiles carry the
     choice; the eye button opens a full-size modal for inspecting a design. --}}
<section class="panel">
  <h2>Template library</h2>
  <p class="sub">The active template is what single-card sticker downloads use. Click the eye to inspect a design at full size.</p>

  <div class="tpl-grid">
    @foreach ($templates as $template)
      <article class="tpl-card {{ $template->is_active ? 'on' : '' }}">
        <div class="tpl-thumbs">
          <img src="{{ asset('storage/'.$template->front_path) }}" alt="{{ $template->name }} — front" loading="lazy">
          <img src="{{ asset('storage/'.$template->back_path) }}" alt="{{ $template->name }} — back" loading="lazy">
          <button type="button" class="tpl-eye" data-modal="tpl-modal-{{ $template->id }}"
                  aria-label="Preview {{ $template->name }} at full size" title="Preview at full size">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>

        <div class="tpl-meta">
          <span class="tpl-name">{{ $template->name }}</span>
          @if ($template->is_active)
            <span class="badge b-ok">Active</span>
          @endif
        </div>
        <p class="small muted" style="margin:2px 0 10px">QR box {{ $template->qr_x }}, {{ $template->qr_y }} &middot; {{ $template->qr_size }}px</p>

        <div class="row row-tight">
          @unless ($template->is_active)
            <form method="POST" action="{{ route('admin.sticker-templates.activate', $template) }}">@csrf
              <button class="btn btn-sm btn-p" type="submit">Use this template</button>
            </form>
            <form method="POST" action="{{ route('admin.sticker-templates.destroy', $template) }}"
                  onsubmit="return confirm('Delete this sticker template?')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-d" type="submit">Delete</button>
            </form>
          @else
            <span class="small muted">Used by sticker downloads</span>
          @endunless
        </div>
      </article>
    @endforeach
  </div>
</section>

{{-- Full-size preview modals. One <dialog> per template so the replace form
     posts to the right route with no JS state to get wrong. --}}
@foreach ($templates as $template)
  <dialog id="tpl-modal-{{ $template->id }}" class="tpl-modal" aria-label="{{ $template->name }} preview">
    <div class="tpl-modal-head">
      <div class="row row-tight">
        <strong>{{ $template->name }}</strong>
        @if ($template->is_active)<span class="badge b-ok">Active</span>@endif
        <span class="small muted">QR box {{ $template->qr_x }}, {{ $template->qr_y }} &middot; {{ $template->qr_size }}px</span>
      </div>
      <button type="button" class="btn btn-sm tpl-close" data-modal="tpl-modal-{{ $template->id }}" aria-label="Close preview">Close &times;</button>
    </div>

    <div class="tpl-big">
      <figure>
        <img src="{{ asset('storage/'.$template->front_path) }}" alt="{{ $template->name }} — front, full size" loading="lazy">
        <figcaption class="small muted">Front</figcaption>
      </figure>
      <figure>
        <span class="tpl-backwrap">
          <img src="{{ asset('storage/'.$template->back_path) }}" alt="{{ $template->name }} — back, full size" loading="lazy">
          <span class="tpl-qrbox" style="left:{{ ($template->qr_x / 638) * 100 }}%;top:{{ ($template->qr_y / 1011) * 100 }}%;width:{{ ($template->qr_size / 638) * 100 }}%;height:{{ ($template->qr_size / 1011) * 100 }}%"></span>
        </span>
        <figcaption class="small muted">Back &middot; the blue box is where the card QR prints</figcaption>
      </figure>
    </div>

    <hr>
    <form method="POST" action="{{ route('admin.sticker-templates.update', $template) }}" enctype="multipart/form-data">
      @csrf @method('PATCH')
      <div class="fgrid">
        <label><span>Replace front</span><input type="file" name="front" accept="image/png"></label>
        <label><span>Replace back</span><input type="file" name="back" accept="image/png"></label>
      </div>
      <button class="btn btn-p" type="submit">Replace files</button>
    </form>
  </dialog>
@endforeach

<style>
/* ── Template library grid ─────────────────────────────────── */
.tpl-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
.tpl-card{
  border:1px solid var(--line);border-radius:var(--radius);padding:12px;
  background:var(--surface);transition:border-color .12s ease;
}
.tpl-card.on{border-color:var(--accent);background:var(--accent-soft)}
.tpl-thumbs{position:relative;display:flex;gap:8px;margin-bottom:10px}
.tpl-thumbs img{
  width:calc(50% - 4px);height:auto;border:1px solid var(--line);border-radius:7px;
  background:var(--surface);display:block;
}
.tpl-eye{
  position:absolute;right:6px;top:6px;width:32px;height:32px;
  display:inline-flex;align-items:center;justify-content:center;
  border:1px solid var(--line-strong);border-radius:8px;cursor:pointer;
  background:rgba(255,255,255,.92);color:var(--text-soft);padding:0;
}
.tpl-eye:hover{color:var(--accent);border-color:var(--accent)}
.tpl-eye:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.tpl-eye svg{width:17px;height:17px}
.tpl-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tpl-name{font-weight:600;font-size:.9rem}

/* ── Full-size preview modal ───────────────────────────────── */
.tpl-modal{
  border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;
  width:min(860px,94vw);max-height:92vh;box-shadow:0 18px 50px rgba(16,24,40,.25);
}
.tpl-modal::backdrop{background:rgba(16,24,40,.55)}
.tpl-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.tpl-big{display:flex;gap:18px;flex-wrap:wrap;justify-content:center}
.tpl-big figure{margin:0;text-align:center;flex:1 1 260px;max-width:380px}
.tpl-big img{width:100%;height:auto;border:1px solid var(--line);border-radius:10px;display:block}
.tpl-backwrap{position:relative;display:block}
.tpl-qrbox{position:absolute;border:2px solid var(--accent);border-radius:3px;pointer-events:none}
</style>

<script>
document.addEventListener('click', function (e) {
  var opener = e.target.closest('.tpl-eye');
  if (opener) { document.getElementById(opener.dataset.modal).showModal(); return; }

  var closer = e.target.closest('.tpl-close');
  if (closer) { document.getElementById(closer.dataset.modal).close(); return; }

  // Clicking the dimmed backdrop closes the dialog. Inside the dialog the
  // click target is a child element, never the <dialog> itself.
  if (e.target instanceof HTMLDialogElement && e.target.classList.contains('tpl-modal')) {
    e.target.close();
  }
});
</script>
@endsection

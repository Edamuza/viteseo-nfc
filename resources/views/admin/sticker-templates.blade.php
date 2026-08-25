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
    <input id="template-name" type="text" name="name" maxlength="80" value="{{ old('name') }}" required style="width:100%">
    <div class="fgrid" style="margin-top:14px">
      <label><span>Front PNG</span><input type="file" name="front" accept="image/png" required></label>
      <label><span>Back PNG (transparent QR hole)</span><input type="file" name="back" accept="image/png" required></label>
    </div>
    <button class="btn btn-p" type="submit">Save template</button>
  </form>
</section>

@foreach ($templates as $template)
  <section class="panel">
    <div class="row" style="justify-content:space-between;align-items:flex-start">
      <div>
        <h2 style="margin-bottom:6px">{{ $template->name }}</h2>
        @if ($template->is_active)
          <span class="badge b-ok">Active</span>
        @endif
        <p class="small muted">QR box {{ $template->qr_x }}, {{ $template->qr_y }} &middot; {{ $template->qr_size }}px</p>
      </div>
      <div class="row row-tight">
        @unless ($template->is_active)
          <form method="POST" action="{{ route('admin.sticker-templates.activate', $template) }}">@csrf
            <button class="btn btn-sm" type="submit">Set active</button>
          </form>
          <form method="POST" action="{{ route('admin.sticker-templates.destroy', $template) }}"
                onsubmit="return confirm('Delete this sticker template?')">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-d" type="submit">Delete</button>
          </form>
        @endunless
      </div>
    </div>

    <div class="row" style="margin:14px 0;align-items:flex-start">
      <img src="{{ asset('storage/'.$template->front_path) }}" alt="" style="width:120px;height:auto;border:1px solid var(--line);border-radius:8px">
      <div style="position:relative;width:120px">
        <img src="{{ asset('storage/'.$template->back_path) }}" alt="" style="width:120px;height:auto;border:1px solid var(--line);border-radius:8px">
        <span style="position:absolute;border:2px solid #2563eb;pointer-events:none;left:{{ ($template->qr_x / 638) * 100 }}%;top:{{ ($template->qr_y / 1011) * 100 }}%;width:{{ ($template->qr_size / 638) * 100 }}%;height:{{ ($template->qr_size / 1011) * 100 }}%"></span>
      </div>
    </div>

    <form method="POST" action="{{ route('admin.sticker-templates.update', $template) }}" enctype="multipart/form-data">
      @csrf @method('PATCH')
      <div class="fgrid">
        <label><span>Replace front</span><input type="file" name="front" accept="image/png"></label>
        <label><span>Replace back</span><input type="file" name="back" accept="image/png"></label>
      </div>
      <button class="btn btn-p" type="submit">Replace files</button>
    </form>
  </section>
@endforeach

@endsection

@extends('layouts.admin')
@section('title','Site Pages')
@section('body')

<div class="page-head">
  <h1>Global profile pages</h1>
  <p>Edit the plain-text information shown in every Profile page footer. Changes apply to all cards immediately.</p>
</div>

@foreach ($pages as $page)
  <section class="panel">
    <h2>{{ $page->title }}</h2>
    <p class="sub">Global key: <code>{{ $page->key }}</code>. Blank lines create separate paragraphs.</p>

    <form method="POST" action="{{ route('admin.site-pages.update', $page) }}">
      @csrf
      @method('PATCH')

      <label class="fld" for="title-{{ $page->key }}">Modal title</label>
      <input id="title-{{ $page->key }}" type="text" name="title" value="{{ $page->title }}" maxlength="120" required style="width:100%">

      <label class="fld" for="body-{{ $page->key }}" style="margin-top:14px">Body</label>
      <textarea id="body-{{ $page->key }}" name="body" rows="9" required>{{ $page->body }}</textarea>

      <div class="row" style="margin-top:14px">
        <button class="btn btn-p" type="submit">Save {{ $page->title }}</button>
      </div>
    </form>
  </section>
@endforeach

@endsection

@php
  // $href is required. $label names the destination for screen readers and,
  // unless $iconOnly, on the button itself.
  $backLabel = $label ?? 'Back';
@endphp
<a class="btn-2" href="{{ $href }}"@if ($iconOnly ?? false) aria-label="{{ $backLabel }}"@endif>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 19 3 12l7-7"/><path d="M3 12h18"/></svg>
  @unless ($iconOnly ?? false)<span>{{ $backLabel }}</span>@endunless
</a>

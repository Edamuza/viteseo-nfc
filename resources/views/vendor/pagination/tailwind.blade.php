{{-- Admin pager. This file overrides Laravel's default "tailwind" paginator
     view, which depends on Tailwind utility classes no admin page loads — it
     rendered both its mobile and desktop blocks at once, with unstyled arrow
     SVGs. The markup here is styled by the .pager rules in layouts/admin. --}}
@if ($paginator->hasPages())
  <nav role="navigation" aria-label="Pagination" class="pager-nav">
    @if ($paginator->onFirstPage())
      <span class="pg-off" aria-disabled="true">&lsaquo; Prev</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; Prev</a>
    @endif

    @foreach ($elements as $element)
      @if (is_string($element))
        <span class="pg-off">{{ $element }}</span>
      @endif

      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="pg-on" aria-current="page">{{ $page }}</span>
          @else
            <a href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
          @endif
        @endforeach
      @endif
    @endforeach

    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next &rsaquo;</a>
    @else
      <span class="pg-off" aria-disabled="true">Next &rsaquo;</span>
    @endif

    <span class="pg-count">
      {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}
    </span>
  </nav>
@endif

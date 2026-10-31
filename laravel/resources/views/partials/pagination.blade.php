@if ($paginator->hasPages())
<nav class="no-print flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-sm sm:flex-row" aria-label="Pagination">
  <p class="text-slate-500">Showing <span class="font-semibold text-slate-700">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span> of <span class="font-semibold text-slate-700">{{ $paginator->total() }}</span></p>
  <div class="flex items-center gap-1">
    @unless ($paginator->onFirstPage())
      <a class="btn-secondary btn-sm" href="{{ $paginator->url(1) }}" aria-label="First page"><i data-lucide="chevrons-left"></i></a>
      <a class="btn-secondary btn-sm" href="{{ $paginator->previousPageUrl() }}"><i data-lucide="chevron-left"></i> Prev</a>
    @endunless
    <span class="px-3 text-slate-600">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
    @if ($paginator->hasMorePages())
      <a class="btn-secondary btn-sm" href="{{ $paginator->nextPageUrl() }}">Next <i data-lucide="chevron-right"></i></a>
      <a class="btn-secondary btn-sm" href="{{ $paginator->url($paginator->lastPage()) }}" aria-label="Last page"><i data-lucide="chevrons-right"></i></a>
    @endif
  </div>
</nav>
@else
<div class="border-t border-slate-100 px-5 py-3 text-sm text-slate-500">{{ $paginator->total() }} record{{ plural($paginator->total()) }}</div>
@endif

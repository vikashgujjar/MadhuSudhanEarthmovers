{{-- @include('partials.page-header', ['title' => ..., 'subtitle' => ..., 'icon' => ...]) --}}
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div class="flex items-center gap-4">
    @if (! empty($icon))<div class="hidden h-12 w-12 items-center justify-center rounded-2xl bg-navy-800 text-brand-500 shadow-card sm:flex"><i data-lucide="{{ $icon }}" class="h-6 w-6"></i></div>@endif
    <div>
      <h1 class="page-title">{{ $title }}</h1>
      @if (! empty($subtitle))<p class="page-subtitle">{{ $subtitle }}</p>@endif
    </div>
  </div>
</div>

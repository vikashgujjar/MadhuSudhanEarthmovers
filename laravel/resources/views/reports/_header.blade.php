{{-- Report page header: title, subtitle and export buttons. --}}
<div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
  <div class="flex items-center gap-4">
    <div class="hidden h-12 w-12 items-center justify-center rounded-2xl bg-navy-800 text-brand-500 shadow-card sm:flex"><i data-lucide="{{ $icon }}" class="h-6 w-6"></i></div>
    <div>
      <h1 class="page-title">{{ $title }}</h1>
      <p class="page-subtitle">{{ $business->business_name }} · {{ $period->label }} · approved transactions</p>
    </div>
  </div>
  @include('partials.export-buttons')
</div>

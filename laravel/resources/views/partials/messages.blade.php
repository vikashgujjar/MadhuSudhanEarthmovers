@php($flash = array_filter(['error' => session('error'), 'warning' => session('warning'), 'success' => session('success'), 'info' => session('info')]))
@if ($flash)
<div class="no-print mb-6 space-y-2">
  @foreach ($flash as $tag => $message)
  <div class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm {{ ['error' => 'border-red-200 bg-red-50 text-red-800', 'warning' => 'border-amber-200 bg-amber-50 text-amber-800', 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'info' => 'border-navy-100 bg-navy-50 text-navy-800'][$tag] }}" role="alert">
    <i data-lucide="{{ ['error' => 'circle-alert', 'warning' => 'triangle-alert', 'success' => 'circle-check', 'info' => 'info'][$tag] }}" class="mt-0.5 h-4 w-4 shrink-0"></i>
    <div class="flex-1">{{ $message }}</div>
    <button type="button" class="opacity-60 hover:opacity-100" data-dismiss aria-label="Dismiss"><i data-lucide="x" class="h-4 w-4"></i></button>
  </div>
  @endforeach
</div>
@endif

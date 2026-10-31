@if ($errors->any())
<div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
  <div class="flex items-center gap-2 font-semibold"><i data-lucide="circle-alert" class="h-4 w-4"></i> Please correct the highlighted fields.</div>
  @if ($errors->has('form'))<ul class="mt-2 list-disc space-y-1 pl-6"><li>{{ $errors->first('form') }}</li></ul>@endif
</div>
@endif

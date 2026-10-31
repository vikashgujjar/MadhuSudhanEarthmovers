{{-- Organisation logo + wordmark. $dark = on navy background. --}}
<div class="flex items-center gap-3">
  @include('partials.logo-mark', ['size' => $size ?? 'h-11 w-11'])
  <div class="min-w-0 leading-none">
    <div class="truncate font-display text-[1.35rem] font-extrabold uppercase tracking-wide {{ ($dark ?? false) ? 'text-white' : 'text-navy-800' }}">{{ $business->namePrimary() }}</div>
    @if ($business->nameSecondary())<div class="mt-1 truncate font-display text-[0.8rem] font-semibold uppercase tracking-[0.32em] text-brand-500">{{ $business->nameSecondary() }}</div>@endif
  </div>
</div>

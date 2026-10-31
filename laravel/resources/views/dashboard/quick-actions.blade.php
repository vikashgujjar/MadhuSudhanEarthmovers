{{-- Quick-entry tiles; each one only for users allowed to use it. --}}
@php($compact = $compact ?? false)
@if ($user->hasCapability('add_income') || $user->hasCapability('add_expense') || $user->hasCapability('add_driver_expense') || $user->hasCapability('record_payment'))
<div class="no-print {{ $compact ? 'mb-6 grid grid-cols-2 gap-3 xl:grid-cols-4' : 'grid gap-4 sm:grid-cols-2 xl:grid-cols-4' }}">
  @can('add_income')
  <a href="{{ route('income.create') }}" class="card flex items-center gap-3 {{ $compact ? 'p-4' : 'p-5' }} transition hover:ring-2 hover:ring-brand-300">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-navy-800 text-brand-500"><i data-lucide="trending-up" class="h-5 w-5"></i></span>
    <span class="min-w-0"><span class="block font-semibold text-navy-800">Add income</span><span class="{{ $compact ? 'hidden sm:block' : 'block' }} text-xs text-slate-500">Hourly, daily, fixed or manual</span></span>
  </a>
  @endcan
  @can('add_expense')
  <a href="{{ route('expenses.create') }}" class="card flex items-center gap-3 {{ $compact ? 'p-4' : 'p-5' }} transition hover:ring-2 hover:ring-brand-300">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white"><i data-lucide="receipt" class="h-5 w-5"></i></span>
    <span class="min-w-0"><span class="block font-semibold text-navy-800">Add expense</span><span class="{{ $compact ? 'hidden sm:block' : 'block' }} text-xs text-slate-500">Diesel, repairs, spare parts…</span></span>
  </a>
  @endcan
  @can('add_driver_expense')
  <a href="{{ route('driver-expenses.create') }}" class="card flex items-center gap-3 {{ $compact ? 'p-4' : 'p-5' }} transition hover:ring-2 hover:ring-brand-300">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-700"><i data-lucide="hard-hat" class="h-5 w-5"></i></span>
    <span class="min-w-0"><span class="block font-semibold text-navy-800">Driver expense</span><span class="{{ $compact ? 'hidden sm:block' : 'block' }} text-xs text-slate-500">Salary, advance, bata…</span></span>
  </a>
  @endcan
  @can('record_payment')
  <a href="{{ route('payments.create') }}" class="card flex items-center gap-3 {{ $compact ? 'p-4' : 'p-5' }} transition hover:ring-2 hover:ring-brand-300">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white"><i data-lucide="wallet" class="h-5 w-5"></i></span>
    <span class="min-w-0"><span class="block font-semibold text-navy-800">Record payment</span><span class="{{ $compact ? 'hidden sm:block' : 'block' }} text-xs text-slate-500">Money received from customer</span></span>
  </a>
  @endcan
</div>
@endif

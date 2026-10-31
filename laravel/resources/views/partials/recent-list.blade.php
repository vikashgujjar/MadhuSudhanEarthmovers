<ul class="divide-y divide-slate-100">
  @forelse ($recent as $t)
  <li>
    <a href="{{ $t['url'] }}" class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50">
      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ ['Expense' => 'bg-brand-50 text-brand-600', 'Payment' => 'bg-emerald-50 text-emerald-600'][$t['kind']] ?? 'bg-navy-50 text-navy-700' }}">
        <i data-lucide="{{ ['Expense' => 'arrow-up-right', 'Payment' => 'wallet'][$t['kind']] ?? 'arrow-down-left' }}" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold text-navy-800">{{ $t['title'] }}</p>
        <p class="truncate text-xs text-slate-500">{{ $t['kind'] }} · {{ $t['obj']->number }} · {{ $t['subtitle'] }} · {{ $t['date']->format('d M Y') }}</p>
      </div>
      <div class="text-right">
        <p class="text-sm font-bold tabular {{ $t['sign'] < 0 ? 'text-brand-600' : 'text-navy-800' }}">{{ $t['sign'] < 0 ? '−' : '' }}{{ $business->currency_symbol }}{{ inr($t['amount']) }}</p>
        <span class="{{ status_badge($t['obj']->status) }}">{{ $t['obj']->statusLabel() }}</span>
      </div>
    </a>
  </li>
  @empty
  <li class="px-5 py-12 text-center text-sm text-slate-500">No transactions recorded yet.</li>
  @endforelse
</ul>

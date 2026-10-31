<div class="tbl-wrap">
  <table class="tbl">
    <thead><tr>
      <th>Vehicle</th><th>Category</th><th>Make / model</th><th class="num">Hourly rate</th><th class="num">Daily rate</th>
      @if ($profitMap !== null)<th class="num">Income</th><th class="num">Expenses</th><th class="num">Profit</th>@endif
      <th>Status</th><th class="text-right">Actions</th>
    </tr></thead>
    <tbody>
    @forelse ($vehicles as $v)
      @php($p = $profitMap[$v->id] ?? null)
      <tr>
        <td><a href="{{ route('vehicles.show', $v) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $v->displayName() }}</a><div class="text-xs text-slate-500">{{ $v->registration_number }}@if ($v->drivers_count) · {{ $v->drivers_count }} driver{{ plural($v->drivers_count) }}@endif</div></td>
        <td><span class="{{ $v->category === 'JCB' ? 'badge-orange' : 'badge-navy' }}">{{ $v->categoryLabel() }}</span></td>
        <td>{{ $v->make }} {{ $v->model }}@if ($v->year) <span class="text-slate-400">({{ $v->year }})</span>@endif</td>
        <td class="num">{{ inr($v->hourly_rate) }}</td>
        <td class="num">{{ inr($v->daily_rate) }}</td>
        @if ($profitMap !== null)
        <td class="num">{{ inr($p['income'] ?? 0) }}</td>
        <td class="num">{{ inr($p['expense'] ?? 0) }}</td>
        <td class="num font-semibold {{ \App\Support\Money::isNegative($p['profit'] ?? 0) ? 'money-neg' : 'money-pos' }}">{{ inr($p['profit'] ?? 0) }}</td>
        @endif
        <td><span class="{{ status_badge($v->status) }}">{{ $v->statusLabel() }}</span></td>
        <td><div class="flex justify-end gap-1">
          <a href="{{ route('vehicles.show', $v) }}" class="btn-ghost btn-sm">View</a>
          @can('manage_masters')<a href="{{ route('vehicles.edit', $v) }}" class="btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>@endcan
        </div></td>
      </tr>
    @empty
      <tr><td colspan="10" class="py-12 text-center text-slate-500">No vehicles found.@can('manage_masters') <a href="{{ route('vehicles.create', $category ? ['category' => $category] : []) }}" class="font-semibold text-brand-600">Register a vehicle</a>.@endcan</td></tr>
    @endforelse
    </tbody>
  </table>
</div>

@extends('layouts.app')
@section('title', 'Dashboard')
@section('header_title', 'Dashboard')
@section('content')
@php($s = $summary)
<div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
  <div>
    <h1 class="page-title">Business Dashboard</h1>
    <p class="page-subtitle">{{ $business->business_name }} · {{ $period->label }} · approved transactions only</p>
  </div>
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center">@include('partials.period-form')</div>
</div>

@include('dashboard.quick-actions', ['compact' => true])

@if ($user->isAdmin() && $pendingApprovalsCount)
<a href="{{ route('approvals.index') }}" class="no-print mb-6 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 hover:bg-amber-100">
  <i data-lucide="clock" class="h-5 w-5 text-amber-600"></i>
  <span class="flex-1"><strong>{{ $pendingApprovalsCount }}</strong> transaction{{ plural($pendingApprovalsCount) }} awaiting your approval ({{ $pending['income'] }} income, {{ $pending['expense'] }} expense, {{ $pending['payment'] }} payment). Pending items are not included in the figures below.</span>
  <i data-lucide="arrow-right" class="h-4 w-4"></i>
</a>
@endif

@php($loss = \App\Support\Money::isNegative($s['net_profit']))
<!-- KPIs -->
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
  <div class="kpi">
    <div class="flex items-start justify-between"><p class="kpi-label">Income billed</p><span class="kpi-icon bg-navy-50 text-navy-700"><i data-lucide="file-text"></i></span></div>
    <p class="kpi-value">{{ $business->currency_symbol }}{{ inr($s['income_total']) }}</p>
    <p class="mt-1 text-xs text-slate-500">{{ $s['income_count'] }} approved entr{{ plural($s['income_count'], 'y', 'ies') }} · work invoiced</p>
  </div>
  <div class="kpi">
    <div class="flex items-start justify-between"><p class="kpi-label">Total expenses</p><span class="kpi-icon bg-brand-50 text-brand-600"><i data-lucide="receipt"></i></span></div>
    <p class="kpi-value">{{ $business->currency_symbol }}{{ inr($s['expense_total']) }}</p>
    <p class="mt-1 text-xs text-slate-500">{{ $s['expense_count'] }} entr{{ plural($s['expense_count'], 'y', 'ies') }} · incl. driver expenses</p>
  </div>
  <div class="kpi {{ $loss ? 'ring-1 ring-red-200' : '' }}">
    <div class="absolute inset-x-0 top-0 h-1 {{ $loss ? 'bg-red-500' : 'bg-brand-500' }}"></div>
    <div class="flex items-start justify-between"><p class="kpi-label">Net operating profit</p><span class="kpi-icon {{ $loss ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600' }}"><i data-lucide="{{ $loss ? 'trending-down' : 'trending-up' }}"></i></span></div>
    <p class="kpi-value {{ $loss ? '!text-red-600' : '' }}">{{ $business->currency_symbol }}{{ inr($s['net_profit']) }}</p>
    <p class="mt-1 text-xs text-slate-500">Income billed − expenses @if ($s['margin'] !== null) · {{ $s['margin'] }}% margin @endif</p>
  </div>
  <div class="kpi">
    <div class="flex items-start justify-between"><p class="kpi-label">Payments received</p><span class="kpi-icon bg-emerald-50 text-emerald-600"><i data-lucide="wallet"></i></span></div>
    <p class="kpi-value">{{ $business->currency_symbol }}{{ inr($s['received_total']) }}</p>
    <p class="mt-1 text-xs text-slate-500">Money actually collected @if ($s['collection_rate'] !== null) · {{ $s['collection_rate'] }}% of billed @endif</p>
  </div>
  <a href="{{ route('customers.outstanding') }}" class="kpi block transition hover:ring-2 hover:ring-brand-200">
    <div class="flex items-start justify-between"><p class="kpi-label">Customer outstanding</p><span class="kpi-icon bg-amber-50 text-amber-600"><i data-lucide="hourglass"></i></span></div>
    <p class="kpi-value">{{ $business->currency_symbol }}{{ inr($s['outstanding_total']) }}</p>
    <p class="mt-1 text-xs text-slate-500">Due from customers @if ($period->end) as of {{ $period->end->format('d M Y') }} @endif</p>
  </a>
</div>

<!-- Category split -->
<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  @foreach ($s['categories'] as $key => $c)
  <a href="{{ $key === 'JCB' ? route('fleet.jcb') : route('fleet.tractors') }}" class="card block p-5 transition hover:ring-2 hover:ring-brand-200">
    <div class="flex items-center gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-navy-800 text-brand-500"><i data-lucide="{{ $key === 'JCB' ? 'construction' : 'tractor' }}" class="h-5 w-5"></i></span>
      <div><p class="text-sm font-bold uppercase tracking-wider text-navy-800">{{ $c['label'] }}</p><p class="text-xs text-slate-500">Income vs expenses</p></div>
    </div>
    <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
      <div><dt class="text-xs text-slate-500">Income</dt><dd class="font-semibold tabular text-navy-800">{{ inr0($c['income']) }}</dd></div>
      <div><dt class="text-xs text-slate-500">Expenses</dt><dd class="font-semibold tabular text-brand-600">{{ inr0($c['expense']) }}</dd></div>
      <div><dt class="text-xs text-slate-500">Profit</dt><dd class="font-semibold tabular {{ \App\Support\Money::isNegative($c['profit']) ? 'text-red-600' : 'text-emerald-700' }}">{{ inr0($c['profit']) }}</dd></div>
    </dl>
  </a>
  @endforeach
  <div class="card p-5">
    <div class="flex items-center gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-600"><i data-lucide="building" class="h-5 w-5"></i></span>
      <div><p class="text-sm font-bold uppercase tracking-wider text-navy-800">General overheads</p><p class="text-xs text-slate-500">Expenses not tied to a vehicle</p></div>
    </div>
    <p class="mt-4 text-xl font-bold tabular text-navy-800">{{ $business->currency_symbol }}{{ inr($s['general_expense']) }}</p>
  </div>
  <a href="{{ route('driver-expenses.index') }}" class="card block p-5 transition hover:ring-2 hover:ring-brand-200">
    <div class="flex items-center gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><i data-lucide="hard-hat" class="h-5 w-5"></i></span>
      <div><p class="text-sm font-bold uppercase tracking-wider text-navy-800">Driver expenses</p><p class="text-xs text-slate-500">Already included in total expenses</p></div>
    </div>
    <p class="mt-4 text-xl font-bold tabular text-navy-800">{{ $business->currency_symbol }}{{ inr($s['driver_expense_total']) }}</p>
  </a>
</div>

<!-- Charts -->
<div class="mt-6 grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Monthly income, expenses &amp; collections</h2></div>
    <div class="card-body"><div class="h-72"><canvas id="trendChart" aria-label="Monthly trend chart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Expenses by category</h2></div>
    <div class="card-body"><div class="h-72"><canvas id="expenseChart" aria-label="Expense breakdown chart"></canvas></div></div>
  </div>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header">
      <h2 class="card-title">Vehicle-wise profitability</h2>
      <a href="{{ route('reports.profitability') }}?{{ $period->querystring() }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Full report →</a>
    </div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Vehicle</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
        <tbody>
        @forelse ($profitRows as $r)
          <tr>
            <td><a href="{{ route('vehicles.show', $r['vehicle']) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $r['vehicle']->displayName() }}</a><div class="text-xs text-slate-500">{{ $r['vehicle']->categoryLabel() }} · {{ $r['vehicle']->registration_number }}</div></td>
            <td class="num">{{ inr($r['income']) }}</td>
            <td class="num">{{ inr($r['expense']) }}</td>
            <td class="num font-semibold {{ \App\Support\Money::isNegative($r['profit']) ? 'money-neg' : 'money-pos' }}">{{ inr($r['profit']) }}</td>
            <td class="num">{{ $r['margin'] !== null ? $r['margin'].'%' : '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="py-10 text-center text-slate-500">No vehicles registered yet.@can('manage_masters') <a href="{{ route('vehicles.create') }}" class="font-semibold text-brand-600">Add a vehicle</a>.@endcan</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">JCB vs Tractor</h2></div>
    <div class="card-body"><div class="h-64"><canvas id="categoryChart" aria-label="JCB vs tractor chart"></canvas></div></div>
  </div>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Recent transactions</h2><span class="text-xs text-slate-500">All statuses</span></div>
    @include('partials.recent-list')
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Top driver expenses</h2><a href="{{ route('reports.driver-expenses') }}?{{ $period->querystring() }}" class="text-sm font-semibold text-brand-600">Report →</a></div>
    <ul class="divide-y divide-slate-100">
      @forelse ($driverRows as $r)
      <li class="flex items-center justify-between px-5 py-3 text-sm">
        <a href="{{ route('drivers.show', $r['driver']) }}" class="font-medium text-navy-800 hover:text-brand-600">{{ $r['driver']->name }}</a>
        <span class="font-semibold tabular">{{ $business->currency_symbol }}{{ inr($r['total']) }}</span>
      </li>
      @empty
      <li class="px-5 py-10 text-center text-sm text-slate-500">No approved driver expenses in this period.</li>
      @endforelse
    </ul>
  </div>
</div>

<script type="application/json" id="trend-data">{!! json_for_script($trend) !!}</script>
<script type="application/json" id="expense-data">{!! json_for_script($expenseChart) !!}</script>
<script type="application/json" id="category-data">{!! json_for_script($categoryChart) !!}</script>
@endsection
@push('scripts')
<script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
<script src="{{ asset('js/charts.js') }}"></script>
@endpush

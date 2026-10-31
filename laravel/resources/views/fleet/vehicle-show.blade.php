@extends('layouts.app')
@section('title', $vehicle->displayName())
@section('header_title', 'Vehicle')
@section('content')
<div class="mb-4"><a href="{{ $vehicle->category === 'JCB' ? route('fleet.jcb') : route('fleet.tractors') }}" class="text-sm font-semibold text-brand-600">← {{ $vehicle->categoryLabel() }} fleet</a></div>
<div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
  <div class="flex items-center gap-4">
    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-navy-800 text-brand-500"><i data-lucide="{{ $vehicle->category === 'JCB' ? 'construction' : 'tractor' }}" class="h-7 w-7"></i></div>
    <div>
      <h1 class="page-title">{{ $vehicle->displayName() }}</h1>
      <p class="page-subtitle">{{ $vehicle->registration_number }} · {{ $vehicle->categoryLabel() }} · <span class="{{ status_badge($vehicle->status) }}">{{ $vehicle->statusLabel() }}</span></p>
    </div>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    @if ($period)@include('partials.period-form')@endif
    @can('add_income')<a href="{{ route('income.create', ['vehicle_category' => $vehicle->category, 'vehicle' => $vehicle->id]) }}" class="btn-primary"><i data-lucide="plus"></i> Income</a>@endcan
    @can('add_expense')<a href="{{ route('expenses.create', ['vehicle_category' => $vehicle->category, 'vehicle' => $vehicle->id]) }}" class="btn-secondary"><i data-lucide="plus"></i> Expense</a>@endcan
    @can('manage_masters')<a href="{{ route('vehicles.edit', $vehicle) }}" class="btn-secondary"><i data-lucide="pencil"></i> Edit</a>@endcan
    @can('admin')<a href="{{ route('vehicles.delete', $vehicle) }}" class="btn-ghost text-red-600" aria-label="Delete vehicle"><i data-lucide="trash-2"></i></a>@endcan
  </div>
</div>

@if ($stats)
<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
  <div class="kpi"><p class="kpi-label">Income billed</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($stats['income']) }}</p><p class="mt-1 text-xs text-slate-500">{{ $stats['jobs'] }} job{{ plural($stats['jobs']) }} · {{ $period->label }}</p></div>
  <div class="kpi"><p class="kpi-label">Expenses</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($stats['expense']) }}</p><p class="mt-1 text-xs text-slate-500">Diesel {{ inr0($stats['diesel_expense']) }} · Driver {{ inr0($stats['driver_expense']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Profit</p><p class="kpi-value {{ \App\Support\Money::isNegative($stats['profit']) ? '!text-red-600' : '!text-emerald-700' }}">{{ $business->currency_symbol }}{{ inr($stats['profit']) }}</p><p class="mt-1 text-xs text-slate-500">{{ $stats['margin'] !== null ? $stats['margin'].'% margin' : '—' }}</p></div>
  <div class="kpi"><p class="kpi-label">Collected</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($stats['received']) }}</p><p class="mt-1 text-xs text-slate-500">Against this vehicle's invoices</p></div>
  <div class="kpi"><p class="kpi-label">Expense mix</p>
    <ul class="mt-2 space-y-1 text-xs">@forelse ($expenseBreakdown as $e)<li class="flex justify-between"><span class="text-slate-500">{{ $e['label'] }}</span><span class="font-semibold tabular">{{ inr0($e['amount']) }}</span></li>@empty<li class="text-slate-400">No expenses</li>@endforelse</ul>
  </div>
</div>
@endif

<div class="grid gap-6 xl:grid-cols-3">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Vehicle details</h2></div>
    <dl class="divide-y divide-slate-100 text-sm">
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Make / model</dt><dd class="font-medium">{{ $vehicle->make ?: '—' }} {{ $vehicle->model }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Year</dt><dd class="font-medium">{{ $vehicle->year ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Chassis no.</dt><dd class="font-medium">{{ $vehicle->chassis_number ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Engine no.</dt><dd class="font-medium">{{ $vehicle->engine_number ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Purchased</dt><dd class="font-medium">{{ $vehicle->purchase_date?->format('d M Y') ?? '—' }}@if ($vehicle->purchase_cost) · {{ $business->currency_symbol }}{{ inr0($vehicle->purchase_cost) }}@endif</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Hourly rate</dt><dd class="font-medium">{{ $business->currency_symbol }}{{ inr($vehicle->hourly_rate) }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Daily rate</dt><dd class="font-medium">{{ $business->currency_symbol }}{{ inr($vehicle->daily_rate) }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Insurance expiry</dt><dd class="font-medium">{{ $vehicle->insurance_expiry?->format('d M Y') ?? '—' }}</dd></div>
      <div class="px-5 py-2.5"><dt class="text-slate-500">Drivers</dt><dd class="mt-1 flex flex-wrap gap-1">@forelse ($drivers as $d)<a href="{{ route('drivers.show', $d) }}" class="badge-navy">{{ $d->name }}</a>@empty<span class="text-slate-400">None assigned</span>@endforelse</dd></div>
      @if ($vehicle->notes)<div class="px-5 py-2.5"><dt class="text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line">{{ $vehicle->notes }}</dd></div>@endif
    </dl>
  </div>
  <div class="space-y-6 xl:col-span-2">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Recent income</h2><a href="{{ route('income.index', ['vehicle' => $vehicle->id, 'vehicle_category' => $vehicle->category]) }}" class="text-sm font-semibold text-brand-600">All →</a></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Billing</th><th class="num">Amount</th><th>Status</th></tr></thead>
        <tbody>@forelse ($incomes as $i)<tr><td>{{ $i->date->format('d M Y') }}</td><td><a class="font-semibold text-navy-800 hover:text-brand-600" href="{{ route('income.show', $i->id) }}">{{ $i->number }}</a></td><td>{{ $i->customer->name }}</td><td>{{ $i->billingLabel() }}</td><td class="num">{{ inr($i->amount) }}</td><td><span class="{{ status_badge($i->status) }}">{{ $i->statusLabel() }}</span></td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-slate-500">No income recorded for this vehicle.</td></tr>@endforelse</tbody>
      </table></div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Recent expenses</h2><a href="{{ route('expenses.index', ['vehicle' => $vehicle->id, 'vehicle_category' => $vehicle->category]) }}" class="text-sm font-semibold text-brand-600">All →</a></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Date</th><th>No.</th><th>Category</th><th>Driver / vendor</th><th class="num">Amount</th><th>Status</th></tr></thead>
        <tbody>@forelse ($expenses as $e)<tr><td>{{ $e->date->format('d M Y') }}</td><td><a class="font-semibold text-navy-800 hover:text-brand-600" href="{{ route('expenses.show', $e->id) }}">{{ $e->number }}</a></td><td>{{ $e->categoryLabel() }}</td><td>{{ $e->driver?->name ?: ($e->vendor ?: '—') }}</td><td class="num">{{ inr($e->amount) }}</td><td><span class="{{ status_badge($e->status) }}">{{ $e->statusLabel() }}</span></td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-slate-500">No expenses recorded for this vehicle.</td></tr>@endforelse</tbody>
      </table></div>
    </div>
  </div>
</div>
@endsection

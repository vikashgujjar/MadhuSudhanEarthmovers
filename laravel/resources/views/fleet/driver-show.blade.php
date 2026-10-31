@extends('layouts.app')
@section('title', $driver->name)
@section('header_title', 'Driver')
@section('content')
<div class="mb-4"><a href="{{ route('drivers.index') }}" class="text-sm font-semibold text-brand-600">← Drivers</a></div>
<div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
  <div class="flex items-center gap-4">
    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-navy-800 text-xl font-bold text-brand-500">{{ mb_strtoupper(mb_substr($driver->name, 0, 1)) }}</div>
    <div><h1 class="page-title">{{ $driver->name }}</h1><p class="page-subtitle">{{ $driver->phone ?: 'No phone' }} · <span class="{{ status_badge($driver->status) }}">{{ $driver->statusLabel() }}</span></p></div>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    @if ($period)@include('partials.period-form')@endif
    @can('add_driver_expense')
      @php($prefill = ['driver' => $driver->id] + ($driver->assignedVehicle ? ['vehicle_category' => $driver->assignedVehicle->category, 'vehicle' => $driver->assignedVehicle->id] : []))
      <a href="{{ route('driver-expenses.create', $prefill) }}" class="btn-primary"><i data-lucide="plus"></i> Driver expense</a>
    @endcan
    @can('manage_masters')<a href="{{ route('drivers.edit', $driver) }}" class="btn-secondary"><i data-lucide="pencil"></i> Edit</a>@endcan
    @can('admin')<a href="{{ route('drivers.delete', $driver) }}" class="btn-ghost text-red-600" aria-label="Delete driver"><i data-lucide="trash-2"></i></a>@endcan
  </div>
</div>

<div class="grid gap-6 xl:grid-cols-3">
  <div class="space-y-6">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Driver details</h2></div>
      <dl class="divide-y divide-slate-100 text-sm">
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Alternate phone</dt><dd class="font-medium">{{ $driver->alternate_phone ?: '—' }}</dd></div>
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Licence no.</dt><dd class="font-medium">{{ $driver->license_number ?: '—' }}</dd></div>
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Licence expiry</dt><dd class="font-medium">{{ $driver->license_expiry?->format('d M Y') ?? '—' }}</dd></div>
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Assigned vehicle</dt><dd class="font-medium">@if ($driver->assignedVehicle)<a class="hover:text-brand-600" href="{{ route('vehicles.show', $driver->assignedVehicle) }}">{{ $driver->assignedVehicle->displayName() }}</a>@else — @endif</dd></div>
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Monthly salary</dt><dd class="font-medium">{{ $business->currency_symbol }}{{ inr($driver->monthly_salary) }}</dd></div>
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Daily allowance</dt><dd class="font-medium">{{ $business->currency_symbol }}{{ inr($driver->daily_allowance) }}</dd></div>
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Joined</dt><dd class="font-medium">{{ $driver->joining_date?->format('d M Y') ?? '—' }}</dd></div>
        @if ($driver->address)<div class="px-5 py-2.5"><dt class="text-slate-500">Address</dt><dd class="mt-1 whitespace-pre-line">{{ $driver->address }}</dd></div>@endif
        @if ($driver->notes)<div class="px-5 py-2.5"><dt class="text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line">{{ $driver->notes }}</dd></div>@endif
      </dl>
    </div>
    @if ($period)
    <div class="card">
      <div class="card-header"><h2 class="card-title">Driver expenses · {{ $period->label }}</h2></div>
      <div class="card-body">
        <p class="text-2xl font-bold tabular text-navy-800">{{ $business->currency_symbol }}{{ inr($total) }}</p>
        <p class="text-xs text-slate-500">Approved, included once in total business expenses</p>
        <ul class="mt-4 space-y-2 text-sm">@foreach ($byType as $r)<li class="flex justify-between"><span class="text-slate-600">{{ $r['label'] }}</span><span class="font-semibold tabular">{{ inr($r['amount']) }}</span></li>@endforeach</ul>
        @if ($byVehicle->isNotEmpty())<p class="mt-4 text-xs font-semibold uppercase tracking-wider text-slate-500">By vehicle</p>
        <ul class="mt-2 space-y-2 text-sm">@foreach ($byVehicle as $r)<li class="flex justify-between"><span class="text-slate-600">{{ $r['label'] }}</span><span class="font-semibold tabular">{{ inr($r['amount']) }}</span></li>@endforeach</ul>@endif
      </div>
    </div>
    @endif
  </div>
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Expense history</h2><a href="{{ route('driver-expenses.index', ['driver' => $driver->id]) }}" class="text-sm font-semibold text-brand-600">All →</a></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>No.</th><th>Type</th><th>Vehicle</th><th class="num">Amount</th><th>Status</th></tr></thead>
      <tbody>@forelse ($expenses as $e)<tr><td>{{ $e->date->format('d M Y') }}</td><td><a class="font-semibold text-navy-800 hover:text-brand-600" href="{{ route('expenses.show', $e->id) }}">{{ $e->number }}</a></td><td>{{ $e->isDriverExpense() ? $e->driverTypeLabel() : $e->categoryLabel() }}</td><td>{{ $e->vehicle?->displayName() ?? '—' }}</td><td class="num">{{ inr($e->amount) }}</td><td><span class="{{ status_badge($e->status) }}">{{ $e->statusLabel() }}</span></td></tr>@empty<tr><td colspan="6" class="py-10 text-center text-slate-500">No expenses recorded for this driver.</td></tr>@endforelse</tbody>
    </table></div>
  </div>
</div>
@endsection

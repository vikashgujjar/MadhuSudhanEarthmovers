@extends('layouts.app')
@section('title', $categoryLabel.' Management')
@section('header_title', $categoryLabel.' Management')
@section('content')
<div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
  <div class="flex items-center gap-4">
    <div class="hidden h-14 w-14 items-center justify-center rounded-2xl bg-navy-800 text-brand-500 shadow-card sm:flex"><i data-lucide="{{ $category === 'JCB' ? 'construction' : 'tractor' }}" class="h-7 w-7"></i></div>
    <div><h1 class="page-title">{{ $categoryLabel }} management</h1><p class="page-subtitle">{{ $categoryLabel }} fleet, rates and performance @if ($period) · {{ $period->label }} @endif</p></div>
  </div>
  <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
    @if ($period)@include('partials.period-form')@endif
    @include('partials.export-buttons')
    @can('manage_masters')<a href="{{ route('vehicles.create', ['category' => $category]) }}" class="btn-primary"><i data-lucide="plus"></i> Add {{ $categoryLabel }}</a>@endcan
  </div>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-3 {{ $catTotals ? 'xl:grid-cols-6' : '' }}">
  <div class="kpi"><p class="kpi-label">Registered</p><p class="kpi-value">{{ $counts['total'] }}</p></div>
  <div class="kpi"><p class="kpi-label">Active</p><p class="kpi-value text-emerald-700">{{ $counts['active'] }}</p></div>
  <div class="kpi"><p class="kpi-label">Under maintenance</p><p class="kpi-value text-amber-600">{{ $counts['maintenance'] }}</p></div>
  @if ($catTotals)
  <div class="kpi"><p class="kpi-label">Income billed</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr0($catTotals['income']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Expenses</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr0($catTotals['expense']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Profit</p><p class="kpi-value {{ \App\Support\Money::isNegative($catTotals['profit']) ? '!text-red-600' : '!text-emerald-700' }}">{{ $business->currency_symbol }}{{ inr0($catTotals['profit']) }}</p></div>
  @endif
</div>

<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    @if ($period)<input type="hidden" name="period" value="{{ $period->key }}">@if ($period->key === 'custom')<input type="hidden" name="from" value="{{ $period->startDate() }}"><input type="hidden" name="to" value="{{ $period->endDate() }}">@endif @endif
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search {{ $categoryLabel }} registration, name…" class="form-input sm:max-w-xs">
    <select name="status" class="form-input sm:w-48"><option value="">Any status</option>@foreach (\App\Models\Vehicle::STATUSES as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>
    <button class="btn-navy"><i data-lucide="search"></i> Filter</button>
  </form>
  @include('fleet._vehicle-table')
  {{ $vehicles->links() }}
</div>
@if ($user->hasCapability('add_income') || $user->hasCapability('add_expense'))
<div class="no-print mt-6 flex flex-wrap gap-3">
  @can('add_income')<a href="{{ route('income.create', ['vehicle_category' => $category]) }}" class="btn-navy"><i data-lucide="trending-up"></i> Record {{ $categoryLabel }} income</a>@endcan
  @can('add_expense')<a href="{{ route('expenses.create', ['vehicle_category' => $category]) }}" class="btn-secondary"><i data-lucide="receipt"></i> Record {{ $categoryLabel }} expense</a>@endcan
  <a href="{{ route('income.index', ['vehicle_category' => $category]) }}" class="btn-ghost">View {{ $categoryLabel }} income →</a>
  <a href="{{ route('expenses.index', ['vehicle_category' => $category]) }}" class="btn-ghost">View {{ $categoryLabel }} expenses →</a>
</div>
@endif
@endsection

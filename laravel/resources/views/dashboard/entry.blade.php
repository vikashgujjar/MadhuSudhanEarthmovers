@extends('layouts.app')
@section('title', 'Dashboard')
@section('header_title', 'Dashboard')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">Welcome, {{ $user->first_name ?: $user->username }}</h1>
    <p class="page-subtitle">@if ($user->isViewer()) Read-only access to {{ $business->business_name }} records. @else Your entries in {{ $business->erp_title }} · {{ $period->label }} @endif</p>
  </div>
  @unless ($user->isViewer())@include('partials.period-form')@endunless
</div>

@unless ($user->isViewer())
@include('dashboard.quick-actions')

<div class="mt-6 grid gap-4 sm:grid-cols-3">
  <div class="kpi"><p class="kpi-label">Income I entered</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($myTotals['income']) }}</p><p class="mt-1 text-xs text-slate-500">Pending + approved, {{ $period->label }}</p></div>
  <div class="kpi"><p class="kpi-label">Expenses I entered</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($myTotals['expense']) }}</p><p class="mt-1 text-xs text-slate-500">Pending + approved, {{ $period->label }}</p></div>
  <div class="kpi"><p class="kpi-label">Payments I recorded</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($myTotals['payment']) }}</p><p class="mt-1 text-xs text-slate-500">Pending + approved, {{ $period->label }}</p></div>
</div>

<div class="mt-6 grid gap-4 sm:grid-cols-3">
  <div class="card flex items-center gap-4 p-5"><span class="badge-amber">Pending</span><span class="text-2xl font-bold text-navy-800">{{ $myCounts['PENDING'] }}</span><span class="text-xs text-slate-500">awaiting admin approval</span></div>
  <div class="card flex items-center gap-4 p-5"><span class="badge-green">Approved</span><span class="text-2xl font-bold text-navy-800">{{ $myCounts['APPROVED'] }}</span></div>
  <div class="card flex items-center gap-4 p-5"><span class="badge-red">Rejected</span><span class="text-2xl font-bold text-navy-800">{{ $myCounts['REJECTED'] }}</span><span class="text-xs text-slate-500">edit &amp; resubmit</span></div>
</div>

@if ($rejected->isNotEmpty())
<div class="card mt-6 border-red-200">
  <div class="card-header"><h2 class="card-title text-red-700">Rejected entries — action needed</h2></div>
  <ul class="divide-y divide-slate-100">
    @foreach ($rejected as $obj)
    @php($editRoute = $obj instanceof \App\Models\Income ? 'income.edit' : ($obj instanceof \App\Models\Expense ? 'expenses.edit' : 'payments.edit'))
    <li class="flex flex-col gap-1 px-5 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
      <div><span class="font-semibold text-navy-800">{{ $obj->number }}</span> · {{ $business->currency_symbol }}{{ inr($obj->amount) }} <span class="text-slate-500">— {{ $obj->rejection_reason ?: 'No reason given' }}</span></div>
      <a href="{{ route($editRoute, $obj->id) }}" class="btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit &amp; resubmit</a>
    </li>
    @endforeach
  </ul>
</div>
@endif
@endunless

<div class="card mt-6">
  <div class="card-header"><h2 class="card-title">{{ $user->isViewer() ? 'Recent transactions' : 'My recent entries' }}</h2></div>
  @include('partials.recent-list')
</div>
@endsection

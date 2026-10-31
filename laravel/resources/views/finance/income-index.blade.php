@extends('layouts.app')
@section('title', 'Income')
@section('header_title', 'Income Management')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Income</h1><p class="page-subtitle">JCB and tractor work billed to customers · {{ $period->label }}</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    @can('add_income')<a href="{{ route('income.create') }}" class="btn-primary"><i data-lucide="plus"></i> Add income</a>@endcan
  </div>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><p class="kpi-label">Billed (approved)</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($totalApproved) }}</p></div>
  <div class="kpi"><p class="kpi-label">Received against these</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($totalReceived) }}</p></div>
  <div class="kpi"><p class="kpi-label">Balance due</p><p class="kpi-value !text-amber-700">{{ $business->currency_symbol }}{{ inr($totalOutstanding) }}</p></div>
  <div class="kpi"><p class="kpi-label">Pending approval</p><p class="kpi-value text-slate-500">{{ $business->currency_symbol }}{{ inr($totalPending) }}</p><p class="mt-1 text-xs text-slate-500">Not counted in totals</p></div>
</div>

<div class="card">
  <form method="get" class="no-print grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
    @include('finance._filters-common', ['placeholder' => 'Invoice, customer, vehicle, site…'])
    <select name="vehicle_category" class="form-input"><option value="">JCB &amp; Tractor</option>@foreach (\App\Models\Vehicle::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected(request('vehicle_category') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="vehicle" class="form-input"><option value="">All vehicles</option>@foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') === (string) $v->id)>{{ $v->optionLabel() }}</option>@endforeach</select>
    <select name="customer" class="form-input"><option value="">All customers</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected(request('customer') === (string) $c->id)>{{ $c->name }}</option>@endforeach</select>
    <select name="billing_type" class="form-input"><option value="">All billing types</option>@foreach (\App\Models\Income::BILLING_TYPES as $v => $l)<option value="{{ $v }}" @selected(request('billing_type') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="payment" class="form-input"><option value="">Any payment state</option><option value="unpaid" @selected(request('payment') === 'unpaid')>Unpaid</option><option value="partial" @selected(request('payment') === 'partial')>Partially paid</option><option value="paid" @selected(request('payment') === 'paid')>Fully paid</option></select>
    <div class="flex gap-2"><button class="btn-navy flex-1"><i data-lucide="filter"></i> Filter</button>@if (request()->query())<a href="{{ route('income.index') }}" class="btn-ghost">Clear</a>@endif</div>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Invoice</th><th>Customer</th><th>Vehicle</th><th>Billing</th><th class="num">Billed</th><th class="num">Received</th><th class="num">Balance</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      @forelse ($incomes as $i)
      @php($balance = \App\Support\Money::sub($i->amount, $i->received))
      <tr>
        <td>{{ $i->date->format('d M Y') }}</td>
        <td><a href="{{ route('income.show', $i->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $i->number }}</a></td>
        <td>{{ $i->customer->name }}</td>
        <td><span class="{{ $i->vehicle_category === 'JCB' ? 'badge-orange' : 'badge-navy' }}">{{ $i->categoryLabel() }}</span> {{ $i->vehicle->displayName() }}</td>
        <td class="text-xs">{{ $i->billingLabel() }}@if ($i->quantity)<div class="text-slate-500">{{ qty($i->quantity) }} {{ $i->unitLabel() }} × {{ inr($i->rate) }}</div>@endif</td>
        <td class="num font-semibold">{{ inr($i->amount) }}</td>
        <td class="num text-emerald-700">{{ inr($i->received) }}</td>
        <td class="num {{ \App\Support\Money::isPositive($balance) ? 'font-semibold text-amber-700' : '' }}">{{ inr($balance) }}</td>
        <td><span class="{{ status_badge($i->status) }}">{{ $i->statusLabel() }}</span></td>
        <td class="no-print"><div class="flex justify-end gap-1">
          @if ($i->canEdit($user) && $user->hasCapability('add_income'))<a href="{{ route('income.edit', $i->id) }}" class="btn-ghost btn-sm" title="Edit" aria-label="Edit {{ $i->number }}"><i data-lucide="pencil"></i></a>@endif
          @if ($user->hasCapability('record_payment') && \App\Support\Money::isPositive($balance) && $i->status !== 'REJECTED')<a href="{{ route('payments.create', ['customer' => $i->customer_id, 'income' => $i->id]) }}" class="btn-secondary btn-sm" title="Record payment" aria-label="Record payment for {{ $i->number }}"><i data-lucide="wallet"></i></a>@endif
        </div></td>
      </tr>
      @empty
      <tr><td colspan="10" class="py-12 text-center text-slate-500">No income entries match your filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $incomes->links() }}
</div>
@endsection

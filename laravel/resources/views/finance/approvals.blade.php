@extends('layouts.app')
@section('title', 'Approvals')
@section('header_title', 'Transaction Approval Workflow')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Approvals</h1><p class="page-subtitle">Entries made by Entry Users wait here. Only approved transactions count in dashboards, balances and reports.</p></div>
</div>

<form method="post" action="{{ route('approvals.bulk') }}" id="bulk-form" data-confirm="Approve all selected transactions?">@csrf</form>

@if ($incomes->isEmpty() && $expenses->isEmpty() && $payments->isEmpty())
<div class="card card-body py-16 text-center">
  <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><i data-lucide="badge-check" class="h-7 w-7"></i></div>
  <p class="mt-4 font-semibold text-navy-800">All caught up</p>
  <p class="text-sm text-slate-500">There are no transactions waiting for approval.</p>
</div>
@else
<div class="no-print mb-4 flex justify-end"><button form="bulk-form" class="btn-success"><i data-lucide="check-check"></i> Approve selected</button></div>
@endif

@if ($incomes->isNotEmpty())
<div class="card mb-6">
  <div class="card-header"><h2 class="card-title">Income ({{ $incomes->count() }})</h2></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><input type="checkbox" class="form-check" data-check-all="income" aria-label="Select all income"></th><th>Date</th><th>Invoice</th><th>Customer</th><th>Vehicle</th><th>Billing</th><th class="num">Amount</th><th>Entered by</th><th class="text-right">Action</th></tr></thead>
    <tbody>@foreach ($incomes as $i)
      <tr>
        <td><input type="checkbox" name="income[]" value="{{ $i->id }}" form="bulk-form" class="form-check" data-check-group="income" aria-label="Select {{ $i->number }}"></td>
        <td>{{ $i->date->format('d M Y') }}</td>
        <td><a href="{{ route('income.show', $i->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $i->number }}</a></td>
        <td>{{ $i->customer->name }}</td><td>{{ $i->vehicle->displayName() }}</td>
        <td class="text-xs">{{ $i->billingLabel() }}@if ($i->quantity)<div class="text-slate-500">{{ $i->quantityLabel() }} × {{ inr($i->rate) }}</div>@endif</td>
        <td class="num font-semibold">{{ inr($i->amount) }}</td><td>{{ $i->creator }}</td>
        <td>@include('finance._approve-actions', ['kind' => 'income', 'obj' => $i])</td>
      </tr>@endforeach</tbody>
  </table></div>
</div>
@endif

@if ($expenses->isNotEmpty())
<div class="card mb-6">
  <div class="card-header"><h2 class="card-title">Expenses ({{ $expenses->count() }})</h2></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><input type="checkbox" class="form-check" data-check-all="expense" aria-label="Select all expenses"></th><th>Date</th><th>No.</th><th>Category</th><th>Vehicle</th><th>Driver</th><th class="num">Amount</th><th>Entered by</th><th class="text-right">Action</th></tr></thead>
    <tbody>@foreach ($expenses as $e)
      <tr>
        <td><input type="checkbox" name="expense[]" value="{{ $e->id }}" form="bulk-form" class="form-check" data-check-group="expense" aria-label="Select {{ $e->number }}"></td>
        <td>{{ $e->date->format('d M Y') }}</td>
        <td><a href="{{ route('expenses.show', $e->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $e->number }}</a></td>
        <td>{{ $e->categoryLabel() }}@if ($e->isDriverExpense())<div class="text-xs text-slate-500">{{ $e->driverTypeLabel() }}</div>@endif</td>
        <td>{{ $e->vehicle?->displayName() ?? 'General' }}</td><td>{{ $e->driver?->name ?? '—' }}</td>
        <td class="num font-semibold">{{ inr($e->amount) }}</td><td>{{ $e->creator }}</td>
        <td>@include('finance._approve-actions', ['kind' => 'expense', 'obj' => $e])</td>
      </tr>@endforeach</tbody>
  </table></div>
</div>
@endif

@if ($payments->isNotEmpty())
<div class="card mb-6">
  <div class="card-header"><h2 class="card-title">Payments ({{ $payments->count() }})</h2></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><input type="checkbox" class="form-check" data-check-all="payment" aria-label="Select all payments"></th><th>Date</th><th>Receipt</th><th>Customer</th><th>Invoice</th><th>Mode</th><th class="num">Amount</th><th>Entered by</th><th class="text-right">Action</th></tr></thead>
    <tbody>@foreach ($payments as $p)
      <tr>
        <td><input type="checkbox" name="payment[]" value="{{ $p->id }}" form="bulk-form" class="form-check" data-check-group="payment" aria-label="Select {{ $p->number }}"></td>
        <td>{{ $p->date->format('d M Y') }}</td>
        <td><a href="{{ route('payments.show', $p->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $p->number }}</a></td>
        <td>{{ $p->customer->name }}</td>
        <td>@if ($p->income){{ $p->income->number }}@if ($p->income->status === 'PENDING') <span class="badge-amber">invoice pending</span>@endif @else Advance @endif</td>
        <td>{{ $p->modeLabel() }}</td>
        <td class="num font-semibold">{{ inr($p->amount) }}</td><td>{{ $p->creator }}</td>
        <td>@include('finance._approve-actions', ['kind' => 'payment', 'obj' => $p])</td>
      </tr>@endforeach</tbody>
  </table></div>
</div>
@endif
@endsection

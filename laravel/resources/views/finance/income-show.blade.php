@extends('layouts.app')
@section('title', $income->number)
@section('header_title', 'Income Management')
@section('content')
@php($received = $income->amountReceived())
@php($outstanding = \App\Support\Money::sub($income->amount, $received))
@php($state = $income->paymentState())
<div class="no-print mb-4"><a href="{{ route('income.index') }}" class="text-sm font-semibold text-brand-600">← Income</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">Invoice {{ $income->number }}</h1>
    <p class="page-subtitle">{{ $income->date->format('d F Y') }} · {{ $income->customer->name }} · <span class="{{ status_badge($income->status) }}">{{ $income->statusLabel() }}</span></p>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    <button type="button" class="btn-secondary" data-print><i data-lucide="printer"></i> Print</button>
    @if ($user->hasCapability('record_payment') && \App\Support\Money::isPositive($outstanding) && $income->status !== 'REJECTED')<a href="{{ route('payments.create', ['customer' => $income->customer_id, 'income' => $income->id]) }}" class="btn-primary"><i data-lucide="wallet"></i> Record payment</a>@endif
    @if ($income->canEdit($user) && $user->hasCapability('add_income'))<a href="{{ route('income.edit', $income->id) }}" class="btn-secondary"><i data-lucide="pencil"></i> {{ $income->status === 'REJECTED' ? 'Edit & resubmit' : 'Edit' }}</a>@endif
    @can('admin')<a href="{{ route('income.delete', $income) }}" class="btn-ghost text-red-600"><i data-lucide="trash-2"></i> Delete</a>@endcan
  </div>
</div>
<div class="grid gap-6 xl:grid-cols-3">
  <div class="space-y-6 xl:col-span-2">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Work &amp; billing</h2></div>
      <dl class="grid gap-x-6 text-sm sm:grid-cols-2">
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Customer</dt><dd class="font-medium"><a href="{{ route('customers.show', $income->customer_id) }}" class="hover:text-brand-600">{{ $income->customer->name }}</a></dd></div>
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Vehicle</dt><dd class="font-medium"><a href="{{ route('vehicles.show', $income->vehicle_id) }}" class="hover:text-brand-600">{{ $income->categoryLabel() }} · {{ $income->vehicle->displayName() }}</a></dd></div>
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Driver</dt><dd class="font-medium">{{ $income->driver?->name ?? '—' }}</dd></div>
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Work location</dt><dd class="font-medium">{{ $income->work_location ?: '—' }}</dd></div>
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Billing type</dt><dd class="font-medium">{{ $income->billingLabel() }}</dd></div>
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Calculation</dt><dd class="font-medium">@if ($income->quantity){{ $income->quantityLabel() }} × {{ $business->currency_symbol }}{{ inr($income->rate) }}@else Entered amount @endif</dd></div>
        @if ($income->description)<div class="px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Description</dt><dd class="mt-1 whitespace-pre-line">{{ $income->description }}</dd></div>@endif
      </dl>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Payments against this invoice</h2><span class="badge-{{ ['Paid' => 'green', 'Partially paid' => 'amber'][$state] ?? 'slate' }}">{{ $state }}</span></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Date</th><th>Receipt</th><th>Mode</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead>
        <tbody>@forelse ($payments as $p)<tr><td>{{ $p->date->format('d M Y') }}</td><td>@if ($p->isVisibleTo($user))<a href="{{ route('payments.show', $p->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $p->number }}</a>@else<span class="font-semibold text-navy-800">{{ $p->number }}</span>@endif @if ($p->is_initial) <span class="badge-slate">at billing</span>@endif</td><td>{{ $p->modeLabel() }}</td><td>{{ $p->reference ?: '—' }}</td><td class="num">{{ inr($p->amount) }}</td><td><span class="{{ status_badge($p->status) }}">{{ $p->statusLabel() }}</span></td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-slate-500">No payments recorded yet.</td></tr>@endforelse</tbody>
      </table></div>
    </div>
  </div>
  <div class="space-y-6">
    <div class="card overflow-hidden">
      <div class="bg-navy-800 px-5 py-5 text-white">
        <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Billed amount</p>
        <p class="mt-1 text-3xl font-bold tabular">{{ $business->currency_symbol }}{{ inr($income->amount) }}</p>
      </div>
      <dl class="divide-y divide-slate-100 text-sm">
        <div class="flex justify-between px-5 py-3"><dt class="text-slate-500">Received (approved)</dt><dd class="font-semibold tabular text-emerald-700">{{ $business->currency_symbol }}{{ inr($received) }}</dd></div>
        <div class="flex justify-between px-5 py-3"><dt class="font-semibold text-navy-800">Balance due</dt><dd class="font-bold tabular {{ \App\Support\Money::isPositive($outstanding) ? 'text-amber-700' : '' }}">{{ $business->currency_symbol }}{{ inr($outstanding) }}</dd></div>
      </dl>
    </div>
    @include('finance._approval-panel', ['obj' => $income, 'kind' => 'income'])
  </div>
</div>
@endsection

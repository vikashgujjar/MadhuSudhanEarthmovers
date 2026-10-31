@extends('layouts.app')
@section('title', $payment->number)
@section('header_title', 'Payment Tracking')
@section('content')
<div class="no-print mb-4"><a href="{{ route('payments.index') }}" class="text-sm font-semibold text-brand-600">← Payments</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">Receipt {{ $payment->number }}</h1>
    <p class="page-subtitle">{{ $payment->date->format('d F Y') }} · {{ $payment->customer->name }} · <span class="{{ status_badge($payment->status) }}">{{ $payment->statusLabel() }}</span></p>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    <button type="button" class="btn-secondary" data-print><i data-lucide="printer"></i> Print receipt</button>
    @if ($payment->canEdit($user) && $user->hasCapability('record_payment'))<a href="{{ route('payments.edit', $payment->id) }}" class="btn-secondary"><i data-lucide="pencil"></i> {{ $payment->status === 'REJECTED' ? 'Edit & resubmit' : 'Edit' }}</a>@endif
    @can('admin')<a href="{{ route('payments.delete', $payment) }}" class="btn-ghost text-red-600"><i data-lucide="trash-2"></i> Delete</a>@endcan
  </div>
</div>
<div class="grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Payment details</h2><p class="text-2xl font-bold tabular text-emerald-700">{{ $business->currency_symbol }}{{ inr($payment->amount) }}</p></div>
    <dl class="grid text-sm sm:grid-cols-2">
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Customer</dt><dd class="font-medium"><a href="{{ route('customers.show', $payment->customer_id) }}" class="hover:text-brand-600">{{ $payment->customer->name }}</a></dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Against invoice</dt><dd class="font-medium">@if ($payment->income)@if ($payment->income->isVisibleTo($user))<a href="{{ route('income.show', $payment->income_id) }}" class="hover:text-brand-600">{{ $payment->income->number }}</a>@else{{ $payment->income->number }}@endif @else Advance / on account @endif</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Mode</dt><dd class="font-medium">{{ $payment->modeLabel() }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Reference</dt><dd class="font-medium">{{ $payment->reference ?: '—' }}</dd></div>
      @if ($payment->income)
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Invoice billed</dt><dd class="font-medium">{{ $business->currency_symbol }}{{ inr($payment->income->amount) }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Invoice balance due</dt><dd class="font-semibold text-amber-700">{{ $business->currency_symbol }}{{ inr($payment->income->outstanding()) }}</dd></div>
      @endif
      @if ($payment->notes)<div class="px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line">{{ $payment->notes }}</dd></div>@endif
    </dl>
  </div>
  <div class="space-y-6">@include('finance._approval-panel', ['obj' => $payment, 'kind' => 'payment'])</div>
</div>
@endsection

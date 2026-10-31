@extends('layouts.app')
@section('title', $expense->number)
@section('header_title', $expense->isDriverExpense() ? 'Driver Expense Management' : 'Expense Management')
@section('content')
<div class="no-print mb-4"><a href="{{ $expense->isDriverExpense() ? route('driver-expenses.index') : route('expenses.index') }}" class="text-sm font-semibold text-brand-600">← {{ $expense->isDriverExpense() ? 'Driver expenses' : 'Expenses' }}</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">{{ $expense->categoryLabel() }} · {{ $expense->number }}</h1>
    <p class="page-subtitle">{{ $expense->date->format('d F Y') }} · <span class="{{ status_badge($expense->status) }}">{{ $expense->statusLabel() }}</span></p>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    <button type="button" class="btn-secondary" data-print><i data-lucide="printer"></i> Print</button>
    @if ($expense->canEdit($user) && $user->hasCapability($expense->isDriverExpense() ? 'add_driver_expense' : 'add_expense'))<a href="{{ route('expenses.edit', $expense->id) }}" class="btn-secondary"><i data-lucide="pencil"></i> {{ $expense->status === 'REJECTED' ? 'Edit & resubmit' : 'Edit' }}</a>@endif
    @can('admin')<a href="{{ route('expenses.delete', $expense) }}" class="btn-ghost text-red-600"><i data-lucide="trash-2"></i> Delete</a>@endcan
  </div>
</div>
<div class="grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Expense details</h2><p class="text-2xl font-bold tabular text-navy-800">{{ $business->currency_symbol }}{{ inr($expense->amount) }}</p></div>
    <dl class="grid text-sm sm:grid-cols-2">
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Category</dt><dd class="font-medium">{{ $expense->categoryLabel() }}</dd></div>
      @if ($expense->isDriverExpense())<div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Driver expense type</dt><dd class="font-medium">{{ $expense->driverTypeLabel() }}</dd></div>@endif
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Vehicle</dt><dd class="font-medium">@if ($expense->vehicle)<a href="{{ route('vehicles.show', $expense->vehicle_id) }}" class="hover:text-brand-600">{{ $expense->vehicleCategoryLabel() }} · {{ $expense->vehicle->displayName() }}</a>@else General overhead @endif</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Driver</dt><dd class="font-medium">@if ($expense->driver)<a href="{{ route('drivers.show', $expense->driver_id) }}" class="hover:text-brand-600">{{ $expense->driver->name }}</a>@else — @endif</dd></div>
      @if ($expense->litres)<div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Diesel</dt><dd class="font-medium">{{ qty($expense->litres) }} litres</dd></div>@endif
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Paid via</dt><dd class="font-medium">{{ $expense->modeLabel() }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Vendor</dt><dd class="font-medium">{{ $expense->vendor ?: '—' }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Bill number</dt><dd class="font-medium">{{ $expense->bill_number ?: '—' }}</dd></div>
      @if ($expense->attachment)<div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Attachment</dt><dd><a href="{{ route('media', ['path' => $expense->attachment]) }}" target="_blank" rel="noopener" class="font-semibold text-brand-600"><i data-lucide="paperclip" class="mr-1 inline h-4 w-4"></i>View bill</a></dd></div>@endif
      @if ($expense->description)<div class="px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Description</dt><dd class="mt-1 whitespace-pre-line">{{ $expense->description }}</dd></div>@endif
    </dl>
  </div>
  <div class="space-y-6">@include('finance._approval-panel', ['obj' => $expense, 'kind' => 'expense'])</div>
</div>
@endsection

@extends('layouts.app')
@section('title', $expense->number())
@section('header_title', 'Personal Expenses')
@section('content')
<div class="no-print mb-4"><a href="{{ route('personal-expenses.index') }}" class="text-sm font-semibold text-brand-600">← Personal Expenses</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">{{ $business->currency_symbol }}{{ inr($expense->amount) }} · {{ $expense->categoryLabel() }}</h1>
    <p class="page-subtitle">{{ $expense->number() }} · {{ $expense->date->format('d F Y') }}@if ($expense->paid_to) · {{ $expense->paid_to }}@endif</p>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    <button type="button" class="btn-secondary" data-print><i data-lucide="printer"></i> Print</button>
    <a href="{{ route('personal-expenses.edit', $expense) }}" class="btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    @if ($user->isAdmin())<a href="{{ route('personal-expenses.delete', $expense) }}" class="btn-ghost text-red-600" aria-label="Delete personal expense"><i data-lucide="trash-2"></i></a>@endif
  </div>
</div>

<div class="grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Details</h2></div>
    <dl class="grid text-sm sm:grid-cols-2">
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Date</dt><dd class="font-medium">{{ $expense->date->format('d M Y') }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Category</dt><dd class="font-medium">{{ $expense->categoryLabel() }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Amount</dt><dd class="font-semibold">{{ $business->currency_symbol }}{{ inr($expense->amount) }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Paid by</dt><dd class="font-medium">{{ $expense->modeLabel() }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Paid to</dt><dd class="font-medium">{{ $expense->paid_to ?: '—' }}</dd></div>
      <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Entered by</dt><dd class="font-medium">{{ $expense->creator ?? '—' }}</dd></div>
      <div class="px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Description</dt><dd class="mt-1">{{ $expense->description ?: '—' }}</dd></div>
      @if ($expense->notes)<div class="border-t border-slate-100 px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line">{{ $expense->notes }}</dd></div>@endif
    </dl>
  </div>
  @if ($history->isNotEmpty())
  <div class="card">
    <div class="card-header"><h2 class="card-title">Change history</h2></div>
    <ol class="divide-y divide-slate-100 text-sm">
      @foreach ($history as $h)
      @php($nFields = count($h->changes['fields'] ?? []))
      <li class="px-5 py-2.5">@if ($user->isAdmin())<a href="{{ route('audit.show', $h) }}" class="flex justify-between gap-2 hover:text-brand-600">@else<div class="flex justify-between gap-2">@endif<span><span class="font-medium">{{ $h->actionLabel() }}</span> by {{ $h->username ?: 'system' }}@if ($nFields) · {{ $nFields }} field{{ plural($nFields) }}@endif</span><span class="text-xs text-slate-500">{{ $h->timestamp->format('d M, H:i') }}</span>@if ($user->isAdmin())</a>@else</div>@endif</li>
      @endforeach
    </ol>
  </div>
  @endif
</div>
@endsection

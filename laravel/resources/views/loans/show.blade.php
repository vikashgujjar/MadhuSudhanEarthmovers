@extends('layouts.app')
@section('title', $loan->number())
@section('header_title', 'Money Lent')
@section('content')
@php($balance = $loan->balance())
@php($days = $loan->daysToDue())
<div class="no-print mb-4"><a href="{{ route('loans.index') }}" class="text-sm font-semibold text-brand-600">← Money Lent</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">{{ $loan->borrower_name }}</h1>
    <p class="page-subtitle">{{ $loan->number() }} · lent on {{ $loan->date->format('d F Y') }}@if ($loan->phone) · {{ $loan->phone }}@endif · <span class="{{ $loan->statusBadge() }}">{{ $loan->statusLabel() }}</span></p>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    @if ($loan->phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $loan->phone) }}" class="btn-secondary"><i data-lucide="phone"></i> Call</a>@endif
    <button type="button" class="btn-secondary" data-print><i data-lucide="printer"></i> Print</button>
    <a href="{{ route('loans.edit', $loan) }}" class="btn-secondary"><i data-lucide="pencil"></i> Edit</a>
    <a href="{{ route('loans.delete', $loan) }}" class="btn-ghost text-red-600" aria-label="Delete loan"><i data-lucide="trash-2"></i></a>
  </div>
</div>

@if ($loan->status() === 'OVERDUE')
<div class="mb-6 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
  <i data-lucide="bell-ring" class="h-5 w-5 shrink-0 text-red-600"></i>
  <span>Was due back on <strong>{{ $loan->due_date->format('d M Y') }}</strong>, {{ -$days }} day{{ plural(-$days) }} ago. <strong>{{ $business->currency_symbol }}{{ inr($balance) }}</strong> is still to collect.</span>
</div>
@endif

<div class="mb-6 grid gap-4 sm:grid-cols-3">
  <div class="kpi"><p class="kpi-label">Lent</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($loan->amount) }}</p><p class="mt-1 text-xs text-slate-500">Given by {{ $loan->modeLabel() }}</p></div>
  <div class="kpi"><p class="kpi-label">Repaid</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($loan->repaidAmount()) }}</p><p class="mt-1 text-xs text-slate-500">{{ $repayments->count() }} repayment{{ plural($repayments->count()) }}</p></div>
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div><p class="kpi-label">Still to collect</p><p class="kpi-value {{ \App\Support\Money::isPositive($balance) ? '!text-amber-700' : '' }}">{{ $business->currency_symbol }}{{ inr($balance) }}</p>
    <p class="mt-1 text-xs text-slate-500">@if ($loan->due_date)Due {{ $loan->due_date->format('d M Y') }}@else No due date set @endif</p></div>
</div>

<div class="grid gap-6 xl:grid-cols-3">
  <div class="space-y-6 xl:col-span-2">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Repayments received</h2></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Date</th><th>Mode</th><th>Notes</th><th class="num">Amount</th><th class="num">Balance after</th><th class="no-print"></th></tr></thead>
        <tbody>
        @php($running = $loan->amount)
        @forelse ($repayments as $r)
        @php($running = \App\Support\Money::sub($running, $r->amount))
        <tr>
          <td>{{ $r->date->format('d M Y') }}</td>
          <td>{{ $r->modeLabel() }}</td>
          <td class="max-w-xs whitespace-normal text-slate-600">{{ $r->notes }}</td>
          <td class="num font-semibold text-emerald-700">{{ inr($r->amount) }}</td>
          <td class="num">{{ inr($running) }}</td>
          <td class="no-print text-right"><a href="{{ route('loans.repayments.delete', [$loan, $r]) }}" class="btn-ghost btn-sm text-red-600" aria-label="Delete repayment of {{ inr($r->amount) }}"><i data-lucide="trash-2"></i></a></td>
        </tr>
        @empty
        <tr><td colspan="6" class="py-10 text-center text-slate-500">Nothing repaid yet.</td></tr>
        @endforelse
        </tbody>
      </table></div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Details</h2></div>
      <dl class="grid text-sm sm:grid-cols-2">
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Phone</dt><dd class="font-medium">{{ $loan->phone ?: '—' }}</dd></div>
        <div class="flex justify-between border-b border-slate-100 px-5 py-3"><dt class="text-slate-500">Entered by</dt><dd class="font-medium">{{ $loan->creator ?? '—' }}</dd></div>
        <div class="px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Address</dt><dd class="mt-1 whitespace-pre-line">{{ $loan->address ?: '—' }}</dd></div>
        @if ($loan->notes)<div class="border-t border-slate-100 px-5 py-3 sm:col-span-2"><dt class="text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line">{{ $loan->notes }}</dd></div>@endif
      </dl>
    </div>
  </div>
  <div class="space-y-6">
    @if (\App\Support\Money::isPositive($balance))
    <form method="post" action="{{ route('loans.repay', $loan) }}" class="card no-print" novalidate>
      @csrf
      <div class="card-header"><h2 class="card-title">Record repayment</h2></div>
      <div class="card-body space-y-4">
        @php($rb = $errors->getBag('repayment'))
        @if ($rb->any())<div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($rb->all() as $msg)<p>{{ $msg }}</p>@endforeach</div>@endif
        <div>
          <label for="id_amount" class="form-label">Amount received <span class="text-brand-600">*</span></label>
          <input type="number" name="amount" id="id_amount" class="form-input{{ $rb->has('amount') ? ' is-invalid' : '' }}" inputmode="decimal" step="0.01" min="0" value="{{ old('amount') }}" placeholder="{{ $balance }}">
          <p class="form-help">Up to {{ $business->currency_symbol }}{{ inr($balance) }}.</p>
        </div>
        <div>
          <label for="id_date" class="form-label">Date <span class="text-brand-600">*</span></label>
          <input type="date" name="date" id="id_date" class="form-input{{ $rb->has('date') ? ' is-invalid' : '' }}" value="{{ old('date', now()->toDateString()) }}">
        </div>
        <div>
          <label for="id_mode" class="form-label">Received by <span class="text-brand-600">*</span></label>
          <select name="mode" id="id_mode" class="form-input">@foreach (\App\Models\Payment::MODES as $k => $v)<option value="{{ $k }}" @selected(old('mode', 'CASH') === $k)>{{ $v }}</option>@endforeach</select>
        </div>
        <div>
          <label for="id_notes" class="form-label">Notes</label>
          <input type="text" name="notes" id="id_notes" class="form-input" maxlength="500" value="{{ old('notes') }}">
        </div>
        <button class="btn-primary w-full"><i data-lucide="hand-coins"></i> Save repayment</button>
      </div>
    </form>
    @else
    <div class="card card-body text-center text-sm text-emerald-800"><i data-lucide="circle-check" class="mx-auto mb-2 h-8 w-8 text-emerald-600"></i>This loan is fully repaid.</div>
    @endif
    @if ($history->isNotEmpty())
    <div class="card">
      <div class="card-header"><h2 class="card-title">Change history</h2></div>
      <ol class="divide-y divide-slate-100 text-sm">
        @foreach ($history as $h)
        @php($nFields = count($h->changes['fields'] ?? []))
        <li class="px-5 py-2.5"><a href="{{ route('audit.show', $h) }}" class="flex justify-between gap-2 hover:text-brand-600"><span><span class="font-medium">{{ $h->actionLabel() }}</span> by {{ $h->username ?: 'system' }}@if ($nFields) · {{ $nFields }} field{{ plural($nFields) }}@endif</span><span class="text-xs text-slate-500">{{ $h->timestamp->format('d M, H:i') }}</span></a></li>
        @endforeach
      </ol>
    </div>
    @endif
  </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Money Lent')
@section('header_title', 'Money Lent')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Money Lent</h1><p class="page-subtitle">Money given to people, what they have repaid and what is still to collect.</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    <a href="{{ route('loans.create') }}" class="btn-primary"><i data-lucide="plus"></i> Lend money</a>
  </div>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <a href="{{ route('loans.index', ['status' => 'open']) }}" class="kpi block transition hover:ring-2 hover:ring-brand-200">
    <div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div>
    <p class="kpi-label">Still to collect</p>
    <p class="kpi-value !text-amber-700">{{ $business->currency_symbol }}{{ inr($kpi['to_collect']) }}</p>
    <p class="mt-1 text-xs text-slate-500">{{ $kpi['open_count'] }} open loan{{ plural($kpi['open_count']) }}</p>
  </a>
  <a href="{{ route('loans.index', ['status' => 'overdue']) }}" class="kpi block transition hover:ring-2 hover:ring-brand-200 {{ $kpi['overdue_count'] ? 'ring-1 ring-red-200' : '' }}">
    <p class="kpi-label">Overdue</p>
    <p class="kpi-value {{ $kpi['overdue_count'] ? '!text-red-600' : '' }}">{{ $business->currency_symbol }}{{ inr($kpi['overdue']) }}</p>
    <p class="mt-1 text-xs text-slate-500">{{ $kpi['overdue_count'] }} past the due date</p>
  </a>
  <div class="kpi"><p class="kpi-label">Total lent</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($kpi['lent_total']) }}</p><p class="mt-1 text-xs text-slate-500">All loans to date</p></div>
  <div class="kpi"><p class="kpi-label">Total repaid</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($kpi['repaid_total']) }}</p><p class="mt-1 text-xs text-slate-500">Collected back to date</p></div>
</div>

<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, notes…" class="form-input sm:max-w-xs">
    <select name="status" class="form-input sm:w-44">
      <option value="">All loans</option>
      <option value="open" @selected(request('status') === 'open')>To collect</option>
      <option value="overdue" @selected(request('status') === 'overdue')>Overdue</option>
      <option value="settled" @selected(request('status') === 'settled')>Settled</option>
    </select>
    <button class="btn-navy"><i data-lucide="filter"></i> Filter</button>
    @if (request()->query())<a href="{{ route('loans.index') }}" class="btn-ghost">Clear</a>@endif
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Borrower</th><th>Due date</th><th class="num">Lent</th><th class="num">Repaid</th><th class="num">Balance</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      @forelse ($loans as $l)
      @php($days = $l->daysToDue())
      <tr>
        <td>{{ $l->date->format('d M Y') }}<div class="text-xs text-slate-500">{{ $l->number() }}</div></td>
        <td><a href="{{ route('loans.show', $l) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $l->borrower_name }}</a><div class="text-xs text-slate-500">{{ $l->phone ?: '—' }}</div></td>
        <td>@if ($l->due_date){{ $l->due_date->format('d M Y') }}
            @if ($l->status() === 'OVERDUE')<div class="text-xs font-semibold text-red-600">{{ -$days }} day{{ plural(-$days) }} overdue</div>
            @elseif ($l->status() === 'OPEN')<div class="text-xs text-slate-500">{{ $days === 0 ? 'Due today' : "in {$days} day".plural($days) }}</div>@endif
          @else<span class="text-slate-400">No due date</span>@endif</td>
        <td class="num">{{ inr($l->amount) }}</td>
        <td class="num text-emerald-700">{{ inr($l->repaidAmount()) }}</td>
        <td class="num font-semibold {{ \App\Support\Money::isPositive($l->balance()) ? 'text-amber-700' : '' }}">{{ inr($l->balance()) }}</td>
        <td><span class="{{ $l->statusBadge() }}">{{ $l->statusLabel() }}</span></td>
        <td><div class="flex justify-end gap-1">
          <a href="{{ route('loans.show', $l) }}" class="btn-secondary btn-sm">@if (\App\Support\Money::isPositive($l->balance()))<i data-lucide="hand-coins"></i> Receive @else View @endif</a>
          <a href="{{ route('loans.edit', $l) }}" class="btn-secondary btn-sm" aria-label="Edit {{ $l->number() }}"><i data-lucide="pencil"></i></a>
        </div></td>
      </tr>
      @empty
      <tr><td colspan="8" class="py-12 text-center text-slate-500">No loans found. <a class="font-semibold text-brand-600" href="{{ route('loans.create') }}">Record money you lent</a>.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $loans->links() }}
</div>
@endsection

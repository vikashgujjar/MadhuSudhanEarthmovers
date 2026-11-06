@extends('layouts.app')
@section('title', 'Personal Expenses')
@section('header_title', 'Personal Expenses')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Personal Expenses</h1><p class="page-subtitle">The owner's own spending, kept on record. Not counted in business expenses, profit or the Cash Book · {{ $period->label }}</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    <a href="{{ route('personal-expenses.create') }}" class="btn-primary"><i data-lucide="plus"></i> Add personal expense</a>
  </div>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div><p class="kpi-label">Total (filtered)</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($total) }}</p><p class="mt-1 text-xs text-slate-500">{{ $count }} entr{{ plural($count, 'y', 'ies') }} match filters</p></div>
  <div class="kpi"><p class="kpi-label">This month</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($thisMonth) }}</p><p class="mt-1 text-xs text-slate-500">{{ now()->format('F Y') }}</p></div>
  <div class="kpi sm:col-span-2">
    <p class="kpi-label">By category</p>
    <div class="mt-3 flex flex-wrap gap-2">
      @forelse ($byCategory as $r)<a href="{{ query_with(['category' => $r['key'], 'page' => null]) }}" class="badge-orange">{{ $r['label'] }} · {{ inr0($r['t']) }}</a>@empty<span class="text-sm text-slate-400">No entries</span>@endforelse
    </div>
    <p class="mt-3 text-xs text-slate-500">This financial year: <strong>{{ $business->currency_symbol }}{{ inr($thisFy) }}</strong></p>
  </div>
</div>

<div class="card">
  <form method="get" class="no-print grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Paid to, description, notes…" class="form-input lg:col-span-2">
    @include('partials.period-fields')
    <select name="category" class="form-input"><option value="">All categories</option>@foreach (\App\Models\PersonalExpense::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected(request('category') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="mode" class="form-input"><option value="">Any payment mode</option>@foreach (\App\Models\Payment::MODES as $v => $l)<option value="{{ $v }}" @selected(request('mode') === $v)>{{ $l }}</option>@endforeach</select>
    <div class="flex gap-2 lg:col-span-6">
      <button class="btn-navy"><i data-lucide="filter"></i> Filter</button>
      @if (request()->query())<a href="{{ route('personal-expenses.index') }}" class="btn-ghost">Clear</a>@endif
    </div>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Category</th><th>Paid to / description</th><th>Paid by</th><th class="num">Amount</th><th class="no-print text-right">Actions</th></tr></thead>
      <tbody>
      @forelse ($expenses as $e)
      <tr>
        <td>{{ $e->date->format('d M Y') }}<div class="text-xs text-slate-500">{{ $e->number() }}</div></td>
        <td><span class="badge-slate">{{ $e->categoryLabel() }}</span></td>
        <td class="max-w-xs whitespace-normal"><a href="{{ route('personal-expenses.show', $e) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $e->paid_to ?: $e->categoryLabel() }}</a>@if ($e->description)<div class="text-xs text-slate-500">{{ $e->description }}</div>@endif</td>
        <td>{{ $e->modeLabel() }}</td>
        <td class="num font-semibold">{{ inr($e->amount) }}</td>
        <td class="no-print"><div class="flex justify-end gap-1">
          <a href="{{ route('personal-expenses.show', $e) }}" class="btn-secondary btn-sm">View</a>
          <a href="{{ route('personal-expenses.edit', $e) }}" class="btn-secondary btn-sm" aria-label="Edit {{ $e->number() }}"><i data-lucide="pencil"></i></a>
        </div></td>
      </tr>
      @empty
      <tr><td colspan="6" class="py-12 text-center text-slate-500">No personal expenses for this period. <a class="font-semibold text-brand-600" href="{{ route('personal-expenses.create') }}">Add one</a>.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $expenses->links() }}
</div>
@endsection

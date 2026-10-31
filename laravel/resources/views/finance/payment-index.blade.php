@extends('layouts.app')
@section('title', 'Payments')
@section('header_title', 'Payment Tracking')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Payments received</h1><p class="page-subtitle">Money actually collected from customers — full, partial and advance payments · {{ $period->label }}</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    @can('record_payment')<a href="{{ route('payments.create') }}" class="btn-primary"><i data-lucide="plus"></i> Record payment</a>@endcan
  </div>
</div>
<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><p class="kpi-label">Received (approved)</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($totalApproved) }}</p></div>
  <div class="kpi"><p class="kpi-label">Pending approval</p><p class="kpi-value text-slate-500">{{ $business->currency_symbol }}{{ inr($totalPending) }}</p></div>
  <div class="kpi sm:col-span-2"><p class="kpi-label">By mode (approved)</p><div class="mt-3 flex flex-wrap gap-2">@forelse ($byMode as $r)<span class="badge-green">{{ $r['label'] }} · {{ inr0($r['t']) }}</span>@empty<span class="text-sm text-slate-400">No approved payments</span>@endforelse</div></div>
</div>
<div class="card">
  <form method="get" class="no-print grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
    @include('finance._filters-common', ['placeholder' => 'Receipt, customer, reference, invoice…'])
    <select name="customer" class="form-input"><option value="">All customers</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected(request('customer') === (string) $c->id)>{{ $c->name }}</option>@endforeach</select>
    <select name="mode" class="form-input"><option value="">Any mode</option>@foreach (\App\Models\Payment::MODES as $v => $l)<option value="{{ $v }}" @selected(request('mode') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="type" class="form-input"><option value="">Invoice &amp; advance</option><option value="invoice" @selected(request('type') === 'invoice')>Against invoice</option><option value="advance" @selected(request('type') === 'advance')>Advance / on account</option></select>
    <div class="flex gap-2"><button class="btn-navy flex-1"><i data-lucide="filter"></i> Filter</button>@if (request()->query())<a href="{{ route('payments.index') }}" class="btn-ghost">Clear</a>@endif</div>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Receipt</th><th>Customer</th><th>Invoice</th><th>Mode</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      @forelse ($payments as $p)
      <tr>
        <td>{{ $p->date->format('d M Y') }}</td>
        <td><a href="{{ route('payments.show', $p->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $p->number }}</a></td>
        <td>{{ $p->customer->name }}</td>
        <td>@if ($p->income)@if ($p->income->isVisibleTo($user))<a href="{{ route('income.show', $p->income_id) }}" class="hover:text-brand-600">{{ $p->income->number }}</a>@else{{ $p->income->number }}@endif @else<span class="badge-slate">Advance</span>@endif</td>
        <td>{{ $p->modeLabel() }}</td>
        <td>{{ $p->reference ?: '—' }}</td>
        <td class="num font-semibold text-emerald-700">{{ inr($p->amount) }}</td>
        <td><span class="{{ status_badge($p->status) }}">{{ $p->statusLabel() }}</span></td>
        <td class="no-print">@if ($p->canEdit($user) && $user->hasCapability('record_payment'))<a href="{{ route('payments.edit', $p->id) }}" class="btn-ghost btn-sm" title="Edit" aria-label="Edit {{ $p->number }}"><i data-lucide="pencil"></i></a>@endif</td>
      </tr>
      @empty
      <tr><td colspan="9" class="py-12 text-center text-slate-500">No payments match your filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $payments->links() }}
</div>
@endsection

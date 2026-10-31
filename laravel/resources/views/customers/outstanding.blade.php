@extends('layouts.app')
@section('title', 'Outstanding Balances')
@section('header_title', 'Outstanding Balances')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Customer outstanding</h1><p class="page-subtitle">Opening balance + approved invoices − approved payments, as of today.</p></div>
  @include('partials.export-buttons')
</div>
<div class="mb-6 grid gap-4 sm:grid-cols-3">
  <div class="kpi"><p class="kpi-label">Billed (listed customers)</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($totals['billed']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Received</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($totals['received']) }}</p></div>
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div><p class="kpi-label">Total outstanding</p><p class="kpi-value !text-amber-700">{{ $business->currency_symbol }}{{ inr($totals['balance']) }}</p>@if ($pendingPayments)<p class="mt-1 text-xs text-slate-500">{{ $pendingPayments }} payment{{ plural($pendingPayments) }} pending approval not deducted</p>@endif</div>
</div>
<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Customer name or phone…" class="form-input sm:max-w-xs">
    <select name="show" class="form-input sm:w-56"><option value="">Only customers with dues</option><option value="all" @selected(request('show') === 'all')>All customers</option></select>
    <button class="btn-navy"><i data-lucide="search"></i> Filter</button>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Customer</th><th class="num">Opening</th><th class="num">Billed</th><th class="num">Received</th><th class="num">Outstanding</th><th class="no-print text-right">Actions</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
    <tr>
      <td><a href="{{ route('customers.show', $r['customer']) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $r['customer']->name }}</a><div class="text-xs text-slate-500">{{ $r['customer']->phone }}</div></td>
      <td class="num">{{ inr($r['opening']) }}</td>
      <td class="num">{{ inr($r['billed']) }}</td>
      <td class="num text-emerald-700">{{ inr($r['received']) }}</td>
      <td class="num font-bold {{ \App\Support\Money::isPositive($r['balance']) ? 'text-amber-700' : (\App\Support\Money::isNegative($r['balance']) ? 'text-emerald-700' : '') }}">{{ inr($r['balance']) }}</td>
      <td class="no-print"><div class="flex justify-end gap-1">
        <a href="{{ route('customers.show', $r['customer']) }}" class="btn-ghost btn-sm">Statement</a>
        @if ($user->hasCapability('record_payment') && \App\Support\Money::isPositive($r['balance']))<a href="{{ route('payments.create', ['customer' => $r['customer']->id]) }}" class="btn-primary btn-sm"><i data-lucide="wallet"></i> Receive</a>@endif
      </div></td>
    </tr>
    @empty
    <tr><td colspan="6" class="py-12 text-center text-slate-500">No outstanding balances.</td></tr>
    @endforelse
    </tbody>
    @if ($rows)<tfoot><tr><td>Total</td><td class="num">{{ inr($totals['opening']) }}</td><td class="num">{{ inr($totals['billed']) }}</td><td class="num">{{ inr($totals['received']) }}</td><td class="num">{{ inr($totals['balance']) }}</td><td class="no-print"></td></tr></tfoot>@endif
  </table></div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Cash Book')
@section('header_title', 'Cash Book')
@section('content')
@include('reports._header', ['title' => 'Cash book', 'icon' => 'book-open'])
<form method="get" class="no-print card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
  @include('partials.period-fields')
  <select name="mode" class="form-input sm:w-48"><option value="">All modes (cash + bank)</option>@foreach (\App\Models\Payment::MODES as $v => $l)<option value="{{ $v }}" @selected($mode === $v)>{{ $l }}</option>@endforeach</select>
  <button class="btn-navy"><i data-lucide="filter"></i> Apply</button>
</form>
<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><p class="kpi-label">Opening balance</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($book['opening']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Receipts</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($book['total_receipts']) }}</p><p class="mt-1 text-xs text-slate-500">Approved customer payments</p></div>
  <div class="kpi"><p class="kpi-label">Payments</p><p class="kpi-value !text-brand-600">{{ $business->currency_symbol }}{{ inr($book['total_payments']) }}</p><p class="mt-1 text-xs text-slate-500">Approved expenses</p></div>
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div><p class="kpi-label">Closing balance</p><p class="kpi-value {{ \App\Support\Money::isNegative($book['closing']) ? '!text-red-600' : '' }}">{{ $business->currency_symbol }}{{ inr($book['closing']) }}</p></div>
</div>
<div class="card">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th>Mode</th><th class="num">Receipts</th><th class="num">Payments</th><th class="num">Balance</th></tr></thead>
    <tbody>
      <tr class="bg-slate-50/70"><td colspan="6" class="font-semibold text-slate-600">Opening balance @if ($period->start) on {{ $period->start->format('d M Y') }} @endif @if ($mode) (selected mode only) @endif</td><td class="num font-semibold">{{ inr($book['opening']) }}</td></tr>
      @forelse ($book['entries'] as $e)
      <tr>
        <td>{{ $e['date']->format('d M Y') }}</td>
        <td><a href="{{ $e['url'] }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $e['number'] }}</a></td>
        <td class="max-w-md whitespace-normal">{{ $e['particulars'] }}</td>
        <td>{{ $e['mode'] }}</td>
        <td class="num text-emerald-700">@unless (\App\Support\Money::isZero($e['receipt'])){{ inr($e['receipt']) }}@endunless</td>
        <td class="num text-brand-600">@unless (\App\Support\Money::isZero($e['payment'])){{ inr($e['payment']) }}@endunless</td>
        <td class="num font-semibold {{ \App\Support\Money::isNegative($e['balance']) ? 'text-red-600' : '' }}">{{ inr($e['balance']) }}</td>
      </tr>
      @empty
      <tr><td colspan="7" class="py-10 text-center text-slate-500">No approved receipts or payments in this period.</td></tr>
      @endforelse
    </tbody>
    <tfoot><tr><td colspan="4">Closing balance</td><td class="num">{{ inr($book['total_receipts']) }}</td><td class="num">{{ inr($book['total_payments']) }}</td><td class="num">{{ inr($book['closing']) }}</td></tr></tfoot>
  </table></div>
</div>
<p class="mt-3 text-xs text-slate-500">The cash book records money actually received and paid. Billed-but-unpaid income does not appear here; see Customer Outstanding. Opening cash balance is set in Business Settings.</p>
@endsection

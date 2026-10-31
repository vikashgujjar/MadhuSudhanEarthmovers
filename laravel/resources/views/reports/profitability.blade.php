@extends('layouts.app')
@section('title', 'Vehicle Profitability')
@section('header_title', 'Vehicle-Wise Profitability')
@section('content')
@include('reports._header', ['title' => 'Vehicle-wise profitability', 'icon' => 'bar-chart-3'])
<form method="get" class="no-print card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
  @include('partials.period-fields')
  <select name="category" class="form-input sm:w-44"><option value="">JCB &amp; Tractor</option>@foreach (\App\Models\Vehicle::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected($category === $v)>{{ $l }}</option>@endforeach</select>
  <button class="btn-navy"><i data-lucide="filter"></i> Apply</button>
</form>
<div class="card mb-6">
  <div class="card-header"><h2 class="card-title">Income, expenses &amp; profit by vehicle</h2></div>
  <div class="card-body"><div class="h-80"><canvas id="vehicleChart" aria-label="Vehicle profitability chart"></canvas></div></div>
</div>
<div class="card">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Vehicle</th><th>Category</th><th class="num">Jobs</th><th class="num">Income</th><th class="num">Diesel</th><th class="num">Driver</th><th class="num">Total expense</th><th class="num">Profit</th><th class="num">Margin</th><th class="num">Collected</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
    <tr>
      <td><a href="{{ route('vehicles.show', $r['vehicle']) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $r['vehicle']->displayName() }}</a><div class="text-xs text-slate-500">{{ $r['vehicle']->registration_number }}</div></td>
      <td><span class="{{ $r['vehicle']->category === 'JCB' ? 'badge-orange' : 'badge-navy' }}">{{ $r['vehicle']->categoryLabel() }}</span></td>
      <td class="num">{{ $r['jobs'] }}</td>
      <td class="num">{{ inr($r['income']) }}</td>
      <td class="num">{{ inr($r['diesel_expense']) }}</td>
      <td class="num">{{ inr($r['driver_expense']) }}</td>
      <td class="num">{{ inr($r['expense']) }}</td>
      <td class="num font-semibold {{ \App\Support\Money::isNegative($r['profit']) ? 'money-neg' : 'money-pos' }}">{{ inr($r['profit']) }}</td>
      <td class="num">{{ $r['margin'] !== null ? $r['margin'].'%' : '—' }}</td>
      <td class="num">{{ inr($r['received']) }}</td>
    </tr>
    @empty
    <tr><td colspan="10" class="py-10 text-center text-slate-500">No vehicles registered.</td></tr>
    @endforelse
    </tbody>
    @if ($rows)<tfoot><tr><td colspan="3">Total (vehicles)</td><td class="num">{{ inr($totals['income']) }}</td><td class="num">{{ inr($totals['diesel_expense']) }}</td><td class="num">{{ inr($totals['driver_expense']) }}</td><td class="num">{{ inr($totals['expense']) }}</td><td class="num">{{ inr($totals['profit']) }}</td><td class="num">{{ $totals['margin'] !== null ? $totals['margin'].'%' : '' }}</td><td class="num">{{ inr($totals['received']) }}</td></tr></tfoot>@endif
  </table></div>
</div>
@unless ($category)
<div class="card mt-6">
  <div class="card-header"><h2 class="card-title">Reconciliation to business totals</h2></div>
  <dl class="divide-y divide-slate-100 text-sm">
    <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Vehicle expenses (above)</dt><dd class="font-medium tabular">{{ inr($totals['expense']) }}</dd></div>
    <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">+ General overheads (not linked to a vehicle)</dt><dd class="font-medium tabular">{{ inr($summary['general_expense']) }}</dd></div>
    <div class="flex justify-between px-5 py-2.5 font-semibold"><dt>= Total business expenses</dt><dd class="tabular">{{ inr($summary['expense_total']) }}</dd></div>
    <div class="flex justify-between px-5 py-2.5 font-semibold"><dt>Net operating profit (income {{ inr($summary['income_total']) }} − expenses)</dt><dd class="tabular {{ \App\Support\Money::isNegative($summary['net_profit']) ? 'text-red-600' : 'text-emerald-700' }}">{{ inr($summary['net_profit']) }}</dd></div>
  </dl>
</div>
@endunless
<script type="application/json" id="vehicle-data">{!! json_for_script($chart) !!}</script>
@endsection
@push('scripts')
<script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
<script src="{{ asset('js/charts.js') }}"></script>
@endpush

@extends('layouts.app')
@section('title', 'Driver Expense Summary')
@section('header_title', 'Financial Reports')
@section('content')
@include('reports._header', ['title' => 'Driver expense summary', 'icon' => 'hard-hat'])
<form method="get" class="no-print card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
  @include('partials.period-fields')
  <button class="btn-navy"><i data-lucide="filter"></i> Apply</button>
</form>
<div class="mb-6 grid gap-4 sm:grid-cols-2">
  <div class="kpi"><p class="kpi-label">Driver expenses</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($totals['total']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Share of total business expenses</p><p class="kpi-value">{{ $share !== null ? $share.'%' : '—' }}</p><p class="mt-1 text-xs text-slate-500">of {{ $business->currency_symbol }}{{ inr($overall['expense_total']) }} — counted once, not added again</p></div>
</div>
<div class="card">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Driver</th>@foreach (\App\Models\Expense::DRIVER_TYPES as $label)<th class="num">{{ $label }}</th>@endforeach<th class="num">Total</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
    <tr><td><a href="{{ route('drivers.show', $r['driver']) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $r['driver']->name }}</a></td>@foreach (array_keys(\App\Models\Expense::DRIVER_TYPES) as $k)<td class="num">@if (isset($r['by_type'][$k]) && ! \App\Support\Money::isZero($r['by_type'][$k])){{ inr($r['by_type'][$k]) }}@else<span class="text-slate-300">—</span>@endif</td>@endforeach<td class="num font-semibold">{{ inr($r['total']) }}</td></tr>
    @empty
    <tr><td colspan="9" class="py-10 text-center text-slate-500">No approved driver expenses in this period.</td></tr>
    @endforelse
    </tbody>
    @if ($rows)<tfoot><tr><td>Total</td>@foreach (array_keys(\App\Models\Expense::DRIVER_TYPES) as $k)<td class="num">{{ inr($totals[$k]) }}</td>@endforeach<td class="num">{{ inr($totals['total']) }}</td></tr></tfoot>@endif
  </table></div>
</div>
@endsection

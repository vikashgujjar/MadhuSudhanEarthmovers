@extends('layouts.app')
@section('title', 'Expenses by Category')
@section('header_title', 'Financial Reports')
@section('content')
@include('reports._header', ['title' => 'Expenses by category', 'icon' => 'pie-chart'])
<form method="get" class="no-print card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
  @include('partials.period-fields')
  <button class="btn-navy"><i data-lucide="filter"></i> Apply</button>
</form>
<div class="card">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Category</th><th class="num">JCB</th><th class="num">Tractor</th><th class="num">General (no vehicle)</th><th class="num">Total</th></tr></thead>
    <tbody>@foreach ($rows as $r)<tr><td class="font-medium">{{ $r['category'] }}</td><td class="num">{{ inr($r['JCB']) }}</td><td class="num">{{ inr($r['TRACTOR']) }}</td><td class="num">{{ inr($r['GENERAL']) }}</td><td class="num font-semibold">{{ inr($r['total']) }}</td></tr>@endforeach</tbody>
    <tfoot><tr><td>Total</td><td class="num">{{ inr($totals['JCB']) }}</td><td class="num">{{ inr($totals['TRACTOR']) }}</td><td class="num">{{ inr($totals['GENERAL']) }}</td><td class="num">{{ inr($totals['total']) }}</td></tr></tfoot>
  </table></div>
</div>
<p class="mt-3 text-xs text-slate-500">Each expense appears in exactly one cell, so the grand total equals total business expenses for the period.</p>
@endsection

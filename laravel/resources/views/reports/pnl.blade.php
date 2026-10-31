@extends('layouts.app')
@section('title', 'Profit & Loss')
@section('header_title', 'Financial Reports')
@section('content')
@include('reports._header', ['title' => 'Profit & loss summary', 'icon' => 'scale'])
<form method="get" class="no-print card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
  @include('partials.period-fields')
  <button class="btn-navy"><i data-lucide="filter"></i> Apply</button>
</form>
<div class="grid gap-6 xl:grid-cols-2">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Income billed</h2><span class="font-bold tabular text-navy-800">{{ $business->currency_symbol }}{{ inr($totalIncome) }}</span></div>
    <table class="tbl"><tbody>
      @forelse ($incomeLines as $l)<tr><td>{{ $l['label'] }}</td><td class="num">{{ inr($l['amount']) }}</td></tr>@empty<tr><td colspan="2" class="py-8 text-center text-slate-500">No approved income.</td></tr>@endforelse
    </tbody><tfoot><tr><td>Total income</td><td class="num">{{ inr($totalIncome) }}</td></tr></tfoot></table>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Expenses</h2><span class="font-bold tabular text-brand-600">{{ $business->currency_symbol }}{{ inr($totalExpense) }}</span></div>
    <table class="tbl"><tbody>
      @forelse ($expenseLines as $l)<tr><td>{{ $l['label'] }}</td><td class="num">{{ inr($l['amount']) }}</td></tr>@empty<tr><td colspan="2" class="py-8 text-center text-slate-500">No approved expenses.</td></tr>@endforelse
    </tbody><tfoot><tr><td>Total expenses</td><td class="num">{{ inr($totalExpense) }}</td></tr></tfoot></table>
  </div>
</div>
<div class="card mt-6 overflow-hidden">
  <div class="flex flex-col gap-2 bg-navy-800 px-6 py-5 text-white sm:flex-row sm:items-center sm:justify-between">
    <p class="font-display text-xl font-bold uppercase tracking-wide">Net operating profit</p>
    <p class="text-3xl font-bold tabular {{ \App\Support\Money::isNegative($net) ? 'text-red-300' : 'text-brand-400' }}">{{ $business->currency_symbol }}{{ inr($net) }}</p>
  </div>
  <dl class="grid divide-y divide-slate-100 text-sm sm:grid-cols-3 sm:divide-x sm:divide-y-0">
    <div class="px-6 py-4"><dt class="text-slate-500">Payments received (cash basis)</dt><dd class="mt-1 text-lg font-semibold tabular text-emerald-700">{{ $business->currency_symbol }}{{ inr($summary['received_total']) }}</dd></div>
    <div class="px-6 py-4"><dt class="text-slate-500">Customer outstanding</dt><dd class="mt-1 text-lg font-semibold tabular text-amber-700">{{ $business->currency_symbol }}{{ inr($summary['outstanding_total']) }}</dd></div>
    <div class="px-6 py-4"><dt class="text-slate-500">Of which driver expenses (already in total)</dt><dd class="mt-1 text-lg font-semibold tabular">{{ $business->currency_symbol }}{{ inr($summary['driver_expense_total']) }}</dd></div>
  </dl>
</div>
@endsection

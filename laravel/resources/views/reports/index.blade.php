@extends('layouts.app')
@section('title', 'Financial Reports')
@section('header_title', 'Financial Reports')
@section('content')
@include('partials.page-header', ['title' => 'Financial reports', 'subtitle' => 'Every report reads live approved records and can be printed or exported to PDF, Excel and CSV with the organisation header.', 'icon' => 'file-bar-chart'])
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
  <a href="{{ route('reports.pnl') }}" class="card group block p-5 transition hover:ring-2 hover:ring-brand-300"><i data-lucide="scale" class="h-7 w-7 text-brand-500"></i><p class="mt-3 font-semibold text-navy-800">Profit &amp; loss summary</p><p class="mt-1 text-sm text-slate-500">Income by category and billing type against expenses by category.</p></a>
  <a href="{{ route('reports.profitability') }}" class="card group block p-5 transition hover:ring-2 hover:ring-brand-300"><i data-lucide="bar-chart-3" class="h-7 w-7 text-brand-500"></i><p class="mt-3 font-semibold text-navy-800">Vehicle-wise profitability</p><p class="mt-1 text-sm text-slate-500">Income, diesel, driver and other costs, profit and margin for each JCB and tractor.</p></a>
  <a href="{{ route('reports.cashbook') }}" class="card group block p-5 transition hover:ring-2 hover:ring-brand-300"><i data-lucide="book-open" class="h-7 w-7 text-brand-500"></i><p class="mt-3 font-semibold text-navy-800">Cash book</p><p class="mt-1 text-sm text-slate-500">Receipts and payments with opening, running and closing balance, by mode.</p></a>
  <a href="{{ route('customers.outstanding') }}" class="card group block p-5 transition hover:ring-2 hover:ring-brand-300"><i data-lucide="hourglass" class="h-7 w-7 text-brand-500"></i><p class="mt-3 font-semibold text-navy-800">Customer outstanding</p><p class="mt-1 text-sm text-slate-500">Opening balance, billed, received and balance due per customer.</p></a>
  <a href="{{ route('reports.driver-expenses') }}" class="card group block p-5 transition hover:ring-2 hover:ring-brand-300"><i data-lucide="hard-hat" class="h-7 w-7 text-brand-500"></i><p class="mt-3 font-semibold text-navy-800">Driver expense summary</p><p class="mt-1 text-sm text-slate-500">Salary, advance, bata and other driver costs per driver.</p></a>
  <a href="{{ route('reports.expense-categories') }}" class="card group block p-5 transition hover:ring-2 hover:ring-brand-300"><i data-lucide="pie-chart" class="h-7 w-7 text-brand-500"></i><p class="mt-3 font-semibold text-navy-800">Expenses by category</p><p class="mt-1 text-sm text-slate-500">Diesel, maintenance, repairs, parts, driver and other — split JCB / Tractor / General.</p></a>
</div>
<h2 class="mb-3 mt-8 text-sm font-bold uppercase tracking-wider text-slate-500">Registers</h2>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <a href="{{ route('income.index') }}" class="card block p-4 hover:ring-2 hover:ring-brand-300"><p class="font-semibold text-navy-800">Income register</p><p class="text-xs text-slate-500">Filter by vehicle, customer, billing, payment state</p></a>
  <a href="{{ route('expenses.index') }}" class="card block p-4 hover:ring-2 hover:ring-brand-300"><p class="font-semibold text-navy-800">Expense register</p><p class="text-xs text-slate-500">Filter by category, vehicle, driver, mode</p></a>
  <a href="{{ route('driver-expenses.index') }}" class="card block p-4 hover:ring-2 hover:ring-brand-300"><p class="font-semibold text-navy-800">Driver expense register</p><p class="text-xs text-slate-500">Filter by driver and expense type</p></a>
  <a href="{{ route('payments.index') }}" class="card block p-4 hover:ring-2 hover:ring-brand-300"><p class="font-semibold text-navy-800">Payments register</p><p class="text-xs text-slate-500">Receipts against invoices and advances</p></a>
</div>
@endsection

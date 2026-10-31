@extends('layouts.app')
@section('title', 'Business Settings')
@section('header_title', 'Business Settings')
@section('content')
@include('partials.page-header', ['title' => 'Business settings', 'subtitle' => 'Organisation profile used on every screen, report, PDF and Excel export.', 'icon' => 'building-2'])
@if ($pendingMigrations)
<form method="post" action="{{ route('settings.migrate') }}" class="mb-6 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center" data-confirm="Apply the database updates now? Take a backup first if you have not.">
  @csrf
  <i data-lucide="database" class="h-5 w-5 shrink-0 text-amber-600"></i>
  <span class="flex-1">This version includes {{ count($pendingMigrations) }} database update{{ plural(count($pendingMigrations)) }} that {{ count($pendingMigrations) === 1 ? 'has' : 'have' }} not been applied yet.</span>
  <button class="btn-primary btn-sm"><i data-lucide="refresh-cw"></i> Apply database updates</button>
</form>
@endif
<form method="post" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-3" novalidate>
  @csrf
  <div class="xl:col-span-3">@include('partials.form-errors')</div>
  <div class="space-y-6 xl:col-span-2">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Organisation profile</h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-field name="business_name" label="Business name" :value="$settings->business_name" required />
        <x-field name="erp_title" label="ERP title" :value="$settings->erp_title" required />
        <x-field name="tagline" label="Tagline" :value="$settings->tagline" wrapper="sm:col-span-2" />
        <x-field name="owner_name" label="Owner name" :value="$settings->owner_name" />
        <x-field name="phone" label="Phone" :value="$settings->phone" />
        <x-field name="email" label="Email" type="email" :value="$settings->email" />
        <x-field name="gstin" label="GSTIN" :value="$settings->gstin" />
        <x-field name="pan" label="PAN" :value="$settings->pan" />
        <x-field name="address" label="Address" type="textarea" :value="$settings->address" wrapper="sm:col-span-2" />
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Accounting &amp; numbering</h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-3">
        <x-field name="currency_symbol" label="Currency symbol" :value="$settings->currency_symbol" required />
        <x-field name="financial_year_start_month" label="Financial year start month" type="select" :options="\App\Models\BusinessSetting::MONTHS" :value="$settings->financial_year_start_month" required help="April for the Indian financial year." />
        <x-field name="opening_cash_balance" label="Opening cash balance" type="number" :value="$settings->opening_cash_balance" required help="Cash and bank balance at the start of record keeping; used by the Cash Book." />
        <x-field name="invoice_prefix" label="Invoice prefix" :value="$settings->invoice_prefix" required />
        <x-field name="receipt_prefix" label="Receipt prefix" :value="$settings->receipt_prefix" required />
        <x-field name="expense_prefix" label="Expense prefix" :value="$settings->expense_prefix" required />
        <x-field name="require_approval" label="Require approval" type="checkbox" :value="$settings->require_approval" wrapper="sm:col-span-3"
          help="Transactions entered by Entry Users stay Pending until an Admin approves them. Only approved transactions are counted in dashboards and reports." />
        <x-field name="report_footer" label="Report footer" :value="$settings->report_footer" wrapper="sm:col-span-3" />
      </div>
    </div>
  </div>
  <div class="space-y-6">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Logo &amp; brand preview</h2></div>
      <div class="card-body space-y-5">
        <div class="rounded-xl bg-navy-800 p-5">@include('partials.logo', ['dark' => true])</div>
        <div class="rounded-xl border border-slate-200 p-5">@include('partials.logo')</div>
        <x-field name="logo" label="Logo" type="file" accept="image/png,image/jpeg,image/webp" />
        @if ($settings->logo)<x-field name="remove_logo" label="Remove the current logo" type="checkbox" :value="false" />@endif
        <p class="form-help">PNG, JPG or WebP image, ideally square, under 2 MB. Without an upload, the built-in earthmover emblem is used.</p>
      </div>
    </div>
    <div class="flex gap-3">
      <button class="btn-primary flex-1"><i data-lucide="save"></i> Save settings</button>
    </div>
  </div>
</form>
@endsection

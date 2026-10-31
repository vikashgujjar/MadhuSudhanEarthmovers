@extends('layouts.app')
@section('title', $driverOnly ? 'Driver Expenses' : 'Expenses')
@section('header_title', $driverOnly ? 'Driver Expense Management' : 'Expense Management')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">{{ $driverOnly ? 'Driver expenses' : 'Expenses' }}</h1>
    <p class="page-subtitle">{{ $driverOnly ? 'Salary, advances, bata and other driver costs. Each is one expense entry, counted once in total expenses.' : 'Diesel, maintenance, repairs, spare parts, driver and general business expenses' }} · {{ $period->label }}</p>
  </div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    @if ($driverOnly)
      @can('add_driver_expense')<a href="{{ route('driver-expenses.create') }}" class="btn-primary"><i data-lucide="plus"></i> Add driver expense</a>@endcan
    @else
      @can('add_driver_expense')<a href="{{ route('driver-expenses.create') }}" class="btn-secondary"><i data-lucide="hard-hat"></i> Driver expense</a>@endcan
      @can('add_expense')<a href="{{ route('expenses.create') }}" class="btn-primary"><i data-lucide="plus"></i> Add expense</a>@endcan
    @endif
  </div>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><p class="kpi-label">Total (approved)</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($totalApproved) }}</p><p class="mt-1 text-xs text-slate-500">{{ $totalCount }} entr{{ plural($totalCount, 'y', 'ies') }} match filters</p></div>
  <div class="kpi"><p class="kpi-label">Pending approval</p><p class="kpi-value text-slate-500">{{ $business->currency_symbol }}{{ inr($totalPending) }}</p><p class="mt-1 text-xs text-slate-500">Not counted in totals</p></div>
  <div class="kpi sm:col-span-2">
    <p class="kpi-label">{{ $driverOnly ? 'By driver' : 'By category' }} (approved)</p>
    <div class="mt-3 flex flex-wrap gap-2">
      @if ($driverOnly)
        @forelse ($byDriver as $r)<a href="{{ query_with(['driver' => $r->did, 'page' => null]) }}" class="badge-navy">{{ $r->dname }} · {{ inr0($r->t) }}</a>@empty<span class="text-sm text-slate-400">No approved entries</span>@endforelse
      @else
        @forelse ($byCategory as $r)<a href="{{ query_with(['category' => $r['key'], 'page' => null]) }}" class="badge-orange">{{ $r['label'] }} · {{ inr0($r['t']) }}</a>@empty<span class="text-sm text-slate-400">No approved entries</span>@endforelse
      @endif
    </div>
  </div>
</div>

<div class="card">
  <form method="get" class="no-print grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
    @include('finance._filters-common', ['placeholder' => 'No., vendor, bill, driver, vehicle…'])
    @unless ($driverOnly)<select name="category" class="form-input"><option value="">All expense types</option>@foreach (\App\Models\Expense::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected(request('category') === $v)>{{ $l }}</option>@endforeach</select>@endunless
    @if ($driverOnly)<select name="type" class="form-input"><option value="">All driver expense types</option>@foreach (\App\Models\Expense::DRIVER_TYPES as $v => $l)<option value="{{ $v }}" @selected(request('type') === $v)>{{ $l }}</option>@endforeach</select>@endif
    <select name="vehicle_category" class="form-input"><option value="">All vehicle categories</option>@foreach (\App\Models\Vehicle::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected(request('vehicle_category') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="vehicle" class="form-input"><option value="">All vehicles</option>@foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') === (string) $v->id)>{{ $v->optionLabel() }}</option>@endforeach</select>
    <select name="driver" class="form-input"><option value="">All drivers</option>@foreach ($drivers as $d)<option value="{{ $d->id }}" @selected(request('driver') === (string) $d->id)>{{ $d->name }}</option>@endforeach</select>
    <select name="mode" class="form-input"><option value="">Any payment mode</option>@foreach (\App\Models\Payment::MODES as $v => $l)<option value="{{ $v }}" @selected(request('mode') === $v)>{{ $l }}</option>@endforeach</select>
    @unless ($driverOnly)<label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="general" value="1" class="form-check" @checked(request('general') === '1')> General only (no vehicle)</label>@endunless
    <div class="flex gap-2"><button class="btn-navy flex-1"><i data-lucide="filter"></i> Filter</button>@if (request()->query())<a href="{{ request()->url() }}" class="btn-ghost">Clear</a>@endif</div>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>No.</th>@if ($driverOnly)<th>Driver</th><th>Type</th>@else<th>Category</th>@endif<th>Vehicle</th>@unless ($driverOnly)<th>Driver</th>@endunless<th>Vendor / bill</th><th>Paid via</th><th class="num">Amount</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      @forelse ($expenses as $e)
      <tr>
        <td>{{ $e->date->format('d M Y') }}</td>
        <td><a href="{{ route('expenses.show', $e->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $e->number }}</a></td>
        @if ($driverOnly)
          <td>@if ($e->driver)<a href="{{ route('drivers.show', $e->driver) }}" class="hover:text-brand-600">{{ $e->driver->name }}</a>@endif</td><td>{{ $e->driverTypeLabel() }}</td>
        @else
          <td><span class="{{ $e->isDriverExpense() ? 'badge-navy' : 'badge-orange' }}">{{ $e->categoryLabel() }}</span>@if ($e->litres)<div class="text-xs text-slate-500">{{ qty($e->litres) }} L</div>@endif</td>
        @endif
        <td>@if ($e->vehicle){{ $e->vehicle->displayName() }}@else<span class="text-slate-400">General</span>@endif</td>
        @unless ($driverOnly)<td>{{ $e->driver?->name ?? '—' }}</td>@endunless
        <td class="max-w-[12rem] truncate">{{ $e->vendor ?: '—' }}@if ($e->bill_number)<div class="text-xs text-slate-500">Bill {{ $e->bill_number }}</div>@endif</td>
        <td>{{ $e->modeLabel() }}</td>
        <td class="num font-semibold">{{ inr($e->amount) }}</td>
        <td><span class="{{ status_badge($e->status) }}">{{ $e->statusLabel() }}</span></td>
        <td class="no-print">@if ($e->canEdit($user) && $user->hasCapability($e->isDriverExpense() ? 'add_driver_expense' : 'add_expense'))<a href="{{ route('expenses.edit', $e->id) }}" class="btn-ghost btn-sm" title="Edit" aria-label="Edit {{ $e->number }}"><i data-lucide="pencil"></i></a>@endif</td>
      </tr>
      @empty
      <tr><td colspan="11" class="py-12 text-center text-slate-500">No expenses match your filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $expenses->links() }}
</div>
@endsection

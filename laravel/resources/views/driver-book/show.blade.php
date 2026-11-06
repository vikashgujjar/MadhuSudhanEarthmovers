@extends('layouts.app')
@section('title', 'Cash Book: '.$driver->name)
@section('header_title', 'Driver Cash Book')
@section('content')
<div class="no-print mb-4"><a href="{{ route('driver-book.index') }}" class="text-sm font-semibold text-brand-600">← Driver Cash Book</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">{{ $driver->name }}</h1>
    <p class="page-subtitle">Monthly salary {{ $business->currency_symbol }}{{ inr($driver->monthly_salary) }}@if ($ledger) · {{ $label }}@endif</p>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    @if ($ledger)@include('partials.export-buttons')@endif
    <a href="{{ route('drivers.show', $driver) }}" class="btn-secondary"><i data-lucide="id-card"></i> Driver</a>
    @if ($ledger)
    @can('add_driver_expense')<a href="{{ route('driver-expenses.create', ['driver' => $driver->id, 'driver_expense_type' => 'SALARY']) }}" class="btn-primary"><i data-lucide="plus"></i> Record payment</a>@endcan
    @endif
  </div>
</div>

@if (! $ledger)
<div class="card mb-6"><div class="card-body text-sm text-slate-600">
  <p class="font-semibold text-navy-800">This driver's cash book has not been set up yet.</p>
  <p class="mt-1">Choose the month to start from and what was already owed at the start of that month. Earlier months and payments are then left out, so old salaries are not shown as unpaid.</p>
  @cannot('admin')<p class="mt-3">An administrator needs to set this up.</p>@endcannot
</div></div>
@else
@if ($driver->ledger_end_month)
<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Salary stops after <strong>{{ $driver->ledger_end_month->format('F Y') }}</strong>. Payments after that still reduce the balance.@can('admin') Change this under Cash book settings.@endcan</div>
@endif

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div>
    <p class="kpi-label">{{ $ledger['balance'] < 0 ? 'Paid extra to driver' : 'Final to pay today' }}</p>
    <p class="kpi-value {{ $ledger['balance'] < 0 ? '!text-red-600' : '!text-amber-700' }}">{{ $business->currency_symbol }}{{ inr(\App\Services\DriverLedger::money(abs($ledger['balance']))) }}</p>
    <p class="mt-1 text-xs text-slate-500">Includes this month's full salary</p>
  </div>
  <div class="kpi"><p class="kpi-label">Salary and additions</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr(\App\Services\DriverLedger::money($totals['in'])) }}</p><p class="mt-1 text-xs text-slate-500">{{ $label }}, opening {{ inr(\App\Services\DriverLedger::money($periodOpening)) }}</p></div>
  <div class="kpi"><p class="kpi-label">Paid and deducted</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr(\App\Services\DriverLedger::money($totals['out'])) }}</p><p class="mt-1 text-xs text-slate-500">{{ $label }}: Salary and Advance @if ($totals['pending'])· {{ inr(\App\Services\DriverLedger::money($totals['pending'])) }} pending approval @endif</p></div>
  <div class="kpi"><p class="kpi-label">Other given</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr(\App\Services\DriverLedger::money($totals['other'])) }}</p><p class="mt-1 text-xs text-slate-500">Bata, food, travel and so on. Does not reduce salary</p></div>
</div>

<div class="card mb-6">
  <form method="get" class="no-print flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <select name="month" class="form-input sm:w-56" aria-label="Month">
      <option value="all" @selected($selected === 'all')>All dates</option>
      @foreach (array_reverse($ledger['months'], true) as $k => $m)<option value="{{ $k }}" @selected($selected === $k)>{{ $m['month']->format('F Y') }}</option>@endforeach
    </select>
    <button class="btn-navy"><i data-lucide="calendar-search"></i> Open</button>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Details</th><th>Mode</th><th class="num">Salary / added</th><th class="num">Paid / deducted</th><th class="num">Other given</th><th class="num">Balance to pay</th></tr></thead>
      <tbody>
      <tr class="bg-slate-50"><td colspan="6" class="font-medium text-slate-600">Opening balance</td><td class="num font-semibold">{{ inr(\App\Services\DriverLedger::money($periodOpening)) }}</td></tr>
      @foreach ($entries as $e)
      <tr class="{{ $e['kind'] === 'pending' ? 'text-slate-400' : '' }}">
        <td>{{ $e['date']->format('d M Y') }}</td>
        <td class="max-w-sm whitespace-normal">
          <span class="font-medium">{{ $e['title'] }}</span>@if ($e['kind'] === 'pending') <span class="badge-slate">Pending approval, not counted</span>@endif
          @if ($e['detail'])<div class="text-xs text-slate-500">@if ($e['url'])<a href="{{ $e['url'] }}" class="hover:text-brand-600">{{ $e['detail'] }}</a>@else{{ $e['detail'] }}@endif</div>@endif
          @if ($e['kind'] === 'adjustment')@can('admin')<a href="{{ route('driver-book.adjustments.delete', [$driver, $e['adjustment']]) }}" class="no-print text-xs font-semibold text-red-600">Delete</a>@endcan @endif
        </td>
        <td>{{ $e['mode'] }}</td>
        <td class="num">{{ $e['in'] ? inr(\App\Services\DriverLedger::money($e['in'])) : '' }}</td>
        <td class="num text-emerald-700">{{ $e['out'] ? inr(\App\Services\DriverLedger::money($e['out'])) : '' }}</td>
        <td class="num text-slate-600">{{ $e['other'] ? inr(\App\Services\DriverLedger::money($e['other'])) : ($e['pending'] ? inr(\App\Services\DriverLedger::money($e['pending'])) : '') }}</td>
        <td class="num font-semibold">{{ inr(\App\Services\DriverLedger::money($e['balance'])) }}</td>
      </tr>
      @endforeach
      <tr class="bg-slate-50"><td colspan="6" class="font-semibold text-navy-800">Closing balance</td><td class="num font-bold text-navy-800">{{ inr(\App\Services\DriverLedger::money($closing)) }}</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-6">
  <div class="card-header"><h2 class="card-title">Month by month</h2></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Month</th><th class="num">Opening</th><th class="num">Salary / added</th><th class="num">Paid / deducted</th><th class="num">Other given</th><th class="num">Closing</th></tr></thead>
    <tbody>
    @foreach (array_reverse($ledger['months'], true) as $k => $m)
    <tr>
      <td><a href="{{ route('driver-book.show', ['driver' => $driver, 'month' => $k]) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $m['month']->format('F Y') }}</a></td>
      <td class="num">{{ inr(\App\Services\DriverLedger::money($m['opening'])) }}</td>
      <td class="num">{{ inr(\App\Services\DriverLedger::money($m['in'])) }}</td>
      <td class="num text-emerald-700">{{ inr(\App\Services\DriverLedger::money($m['out'])) }}</td>
      <td class="num text-slate-600">{{ inr(\App\Services\DriverLedger::money($m['other'])) }}</td>
      <td class="num font-semibold">{{ inr(\App\Services\DriverLedger::money($m['closing'])) }}</td>
    </tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@endif

@can('admin')
<div class="grid gap-6 xl:grid-cols-2">
  <form method="post" action="{{ route('driver-book.setup', $driver) }}" class="card no-print" novalidate>
    @csrf
    <div class="card-header"><h2 class="card-title">Cash book settings</h2></div>
    <div class="card-body space-y-4">
      @if ($errors->default->any())<div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($errors->default->all() as $msg)<p>{{ $msg }}</p>@endforeach</div>@endif
      <div>
        <label for="id_ledger_start_month" class="form-label">Start from month <span class="text-brand-600">*</span></label>
        <input type="month" name="ledger_start_month" id="id_ledger_start_month" max="{{ now()->format('Y-m') }}" class="form-input" value="{{ old('ledger_start_month', $driver->ledger_start_month?->format('Y-m') ?? now()->format('Y-m')) }}">
        <p class="form-help">That month's full salary is added. Payments before this month are not counted.</p>
      </div>
      <div>
        <label for="id_opening_balance" class="form-label">Opening balance <span class="text-brand-600">*</span></label>
        <input type="number" step="0.01" name="opening_balance" id="id_opening_balance" class="form-input" inputmode="decimal" value="{{ old('opening_balance', $driver->opening_balance ?? '0.00') }}">
        <p class="form-help">Salary still owed from before that month. Use a minus figure if the driver had been paid extra. Leave 0 if settled.</p>
      </div>
      <div>
        <label for="id_ledger_end_month" class="form-label">Last salary month</label>
        <input type="month" name="ledger_end_month" id="id_ledger_end_month" class="form-input" value="{{ old('ledger_end_month', $driver->ledger_end_month?->format('Y-m')) }}">
        <p class="form-help">Leave empty while the driver is working. This is filled in automatically when a driver is marked Inactive.</p>
      </div>
      <button class="btn-primary"><i data-lucide="save"></i> Save settings</button>
    </div>
  </form>
  @if ($ledger)
  @php
    $adj = $errors->getBag('adjustment');
  @endphp
  <form method="post" action="{{ route('driver-book.adjustments', $driver) }}" class="card no-print" novalidate>
    @csrf
    <div class="card-header"><h2 class="card-title">Bonus or deduction</h2></div>
    <div class="card-body space-y-4">
      @if ($adj->any())<div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($adj->all() as $msg)<p>{{ $msg }}</p>@endforeach</div>@endif
      <p class="text-sm text-slate-600">This changes what is owed, for example a bonus or a deduction for absent days. No money moves, so it does not affect business expenses. To record money actually given, use Record payment.</p>
      <div class="grid gap-4 sm:grid-cols-2">
        <div><label for="id_adj_kind" class="form-label">Type <span class="text-brand-600">*</span></label><select name="kind" id="id_adj_kind" class="form-input">@foreach (\App\Models\DriverSalaryAdjustment::KINDS as $v => $l)<option value="{{ $v }}" @selected(old('kind') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="id_adj_amount" class="form-label">Amount <span class="text-brand-600">*</span></label><input type="number" step="0.01" min="0" name="amount" id="id_adj_amount" class="form-input{{ $adj->has('amount') ? ' is-invalid' : '' }}" inputmode="decimal" value="{{ $adj->any() ? old('amount') : '' }}"></div>
        <div><label for="id_adj_date" class="form-label">Date <span class="text-brand-600">*</span></label><input type="date" name="date" id="id_adj_date" max="{{ now()->toDateString() }}" class="form-input{{ $adj->has('date') ? ' is-invalid' : '' }}" value="{{ $adj->any() ? old('date') : now()->toDateString() }}"></div>
        <div><label for="id_adj_reason" class="form-label">Reason <span class="text-brand-600">*</span></label><input type="text" name="reason" id="id_adj_reason" maxlength="255" class="form-input{{ $adj->has('reason') ? ' is-invalid' : '' }}" value="{{ $adj->any() ? old('reason') : '' }}"></div>
      </div>
      <button class="btn-secondary"><i data-lucide="save"></i> Save adjustment</button>
    </div>
  </form>
  @endif
</div>
@endcan
@endsection

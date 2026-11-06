@extends('layouts.app')
@section('title', 'Driver Cash Book')
@section('header_title', 'Driver Cash Book')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Driver Cash Book</h1><p class="page-subtitle">Salary owed, what has been paid, and the final amount to pay each driver.</p></div>
  @include('partials.export-buttons')
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-3">
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div><p class="kpi-label">Total to pay drivers</p><p class="kpi-value !text-amber-700">{{ $business->currency_symbol }}{{ inr($totalToPay) }}</p><p class="mt-1 text-xs text-slate-500">As on {{ now()->format('d M Y') }}</p></div>
  <div class="kpi"><p class="kpi-label">Salary and advance paid</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($paidThisMonth) }}</p><p class="mt-1 text-xs text-slate-500">{{ now()->format('F Y') }}</p></div>
  <div class="kpi"><p class="kpi-label">Not set up yet</p><p class="kpi-value {{ $notStarted ? '!text-red-600' : '' }}">{{ $notStarted }}</p><p class="mt-1 text-xs text-slate-500">Driver{{ plural($notStarted) }} without a start month</p></div>
</div>

<div class="card">
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Driver</th><th class="num">Monthly salary</th><th class="num">Paid this month</th><th class="num">Other given</th><th class="num">Final to pay</th><th class="no-print text-right">Actions</th></tr></thead>
      <tbody>
      @forelse ($rows as $r)
      <tr>
        <td><a href="{{ route('driver-book.show', $r['driver']) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $r['driver']->name }}</a>@if ($r['driver']->status !== 'ACTIVE')<div class="text-xs text-slate-500">{{ $r['driver']->statusLabel() }}</div>@endif</td>
        <td class="num">{{ inr($r['driver']->monthly_salary) }}</td>
        @if ($r['started'])
        <td class="num">{{ inr(\App\Services\DriverLedger::money($r['paid'])) }}@if ($r['pending'])<div class="text-xs text-slate-500">+{{ inr(\App\Services\DriverLedger::money($r['pending'])) }} pending</div>@endif</td>
        <td class="num text-slate-600">{{ inr(\App\Services\DriverLedger::money($r['other'])) }}</td>
        <td class="num font-semibold {{ $r['balance'] > 0 ? 'text-amber-700' : '' }}">@if ($r['balance'] < 0){{ inr(\App\Services\DriverLedger::money(-$r['balance'])) }}<div class="text-xs font-normal text-red-600">paid extra</div>@else{{ inr(\App\Services\DriverLedger::money($r['balance'])) }}@endif</td>
        @else
        <td colspan="3" class="text-center text-sm text-slate-500">Not set up yet</td>
        @endif
        <td class="no-print text-right"><a href="{{ route('driver-book.show', $r['driver']) }}" class="btn-secondary btn-sm">{{ $r['started'] ? 'Open' : 'Set up' }}</a></td>
      </tr>
      @empty
      <tr><td colspan="6" class="py-12 text-center text-slate-500">No drivers found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Attendance Summary')
@section('header_title', 'Driver Attendance')
@section('content')
<div class="no-print mb-4"><a href="{{ route('attendance.index') }}" class="text-sm font-semibold text-brand-600">← Daily attendance</a></div>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Attendance summary</h1><p class="page-subtitle">{{ $month->format('F Y') }} · days per driver and total hours from punch times.</p></div>
  @include('partials.export-buttons')
</div>
<div class="card">
  <form method="get" class="no-print flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-input sm:w-48" aria-label="Month">
    <button class="btn-navy"><i data-lucide="calendar-search"></i> Open month</button>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Driver</th><th class="num">Present</th><th class="num">Half day</th><th class="num">Absent</th><th class="num">On leave</th><th class="num">Not marked</th><th class="num">Worked</th></tr></thead>
      <tbody>
      @forelse ($rows as $r)
      <tr>
        <td><a href="{{ route('drivers.show', ['driver' => $r['driver'], 'month' => $month->format('Y-m')]) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $r['driver']->name }}</a></td>
        <td class="num font-semibold text-emerald-700">{{ $r['counts']['PRESENT'] }}</td>
        <td class="num">{{ $r['counts']['HALF_DAY'] }}</td>
        <td class="num text-red-600">{{ $r['counts']['ABSENT'] }}</td>
        <td class="num">{{ $r['counts']['LEAVE'] }}</td>
        <td class="num text-slate-500">{{ $r['unmarked'] ?? '—' }}</td>
        <td class="num">{{ $r['counts']['minutes'] ? \App\Models\DriverAttendance::durationLabel($r['counts']['minutes']) : '—' }}</td>
      </tr>
      @empty
      <tr><td colspan="7" class="py-12 text-center text-slate-500">No drivers found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection

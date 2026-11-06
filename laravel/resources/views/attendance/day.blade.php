@extends('layouts.app')
@section('title', 'Driver Attendance')
@section('header_title', 'Driver Attendance')
@section('content')
@php
  $canMark = $user->hasCapability('mark_attendance');
@endphp
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Driver Attendance</h1><p class="page-subtitle">{{ $date->format('l, d F Y') }} · status and punch in / out for each driver.</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    <a href="{{ route('attendance.monthly', ['month' => $date->format('Y-m')]) }}" class="btn-secondary"><i data-lucide="calendar-days"></i> Monthly summary</a>
  </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-5">
  <div class="kpi"><p class="kpi-label">Present</p><p class="kpi-value !text-emerald-700">{{ $counts['PRESENT'] }}</p></div>
  <div class="kpi"><p class="kpi-label">Half day</p><p class="kpi-value">{{ $counts['HALF_DAY'] }}</p></div>
  <div class="kpi"><p class="kpi-label">Absent</p><p class="kpi-value !text-red-600">{{ $counts['ABSENT'] }}</p></div>
  <div class="kpi"><p class="kpi-label">On leave</p><p class="kpi-value">{{ $counts['LEAVE'] }}</p></div>
  <div class="kpi"><p class="kpi-label">Not marked</p><p class="kpi-value text-slate-500">{{ $unmarked }}</p></div>
</div>

<div class="card">
  <form method="get" class="no-print flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}" class="form-input sm:w-48" aria-label="Attendance date">
    <button class="btn-navy"><i data-lucide="calendar-search"></i> Open date</button>
  </form>
  <form method="post" action="{{ route('attendance.index') }}" data-attendance-sheet novalidate>
    @csrf
    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
    @if ($errors->any())<div class="p-4 pb-0">@include('partials.form-errors')</div>@endif
    <fieldset @disabled(! $canMark)>
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Driver</th><th>Status</th><th>Punch in</th><th>Punch out</th><th>Worked</th><th>Notes</th></tr></thead>
          <tbody>
          @forelse ($drivers as $d)
          @php
            $r = $records->get($d->id);
            $key = "rows.{$d->id}";
            $field = "rows[{$d->id}]";
          @endphp
          <tr data-attendance-row>
            <td><a href="{{ route('drivers.show', $d) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $d->name }}</a><div class="text-xs text-slate-500">{{ $d->assignedVehicle?->displayName() ?? 'No vehicle' }}</div></td>
            <td><select name="{{ $field }}[status]" class="form-input w-36{{ $errors->has("{$key}.status") ? ' is-invalid' : '' }}" data-attendance-status aria-label="Status for {{ $d->name }}">
              <option value="">Not marked</option>
              @foreach (\App\Models\DriverAttendance::STATUSES as $v => $l)<option value="{{ $v }}" @selected(old("{$key}.status", $r?->status) === $v)>{{ $l }}</option>@endforeach
            </select></td>
            <td><input type="time" name="{{ $field }}[punch_in]" value="{{ old("{$key}.punch_in", $r?->punch_in ? substr($r->punch_in, 0, 5) : '') }}" class="form-input w-36{{ $errors->has("{$key}.punch_in") ? ' is-invalid' : '' }}" data-attendance-time aria-label="Punch in for {{ $d->name }}"></td>
            <td><input type="time" name="{{ $field }}[punch_out]" value="{{ old("{$key}.punch_out", $r?->punch_out ? substr($r->punch_out, 0, 5) : '') }}" class="form-input w-36{{ $errors->has("{$key}.punch_out") ? ' is-invalid' : '' }}" data-attendance-time aria-label="Punch out for {{ $d->name }}"></td>
            <td class="whitespace-nowrap">{{ $r?->workedLabel() ?: '—' }}@if ($r?->self_marked)<div class="text-xs text-slate-500">via driver link</div>@endif</td>
            <td><input type="text" name="{{ $field }}[notes]" value="{{ old("{$key}.notes", $r?->notes) }}" maxlength="255" class="form-input min-w-40{{ $errors->has("{$key}.notes") ? ' is-invalid' : '' }}" aria-label="Notes for {{ $d->name }}"></td>
          </tr>
          @empty
          <tr><td colspan="6" class="py-12 text-center text-slate-500">No active drivers on this date.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </fieldset>
    @if ($canMark && $drivers->isNotEmpty())
    <div class="no-print flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-xs text-slate-500">Entering a time marks the driver Present. Setting a driver back to Not marked removes that day's record.</p>
      <button class="btn-primary"><i data-lucide="save"></i> Save attendance</button>
    </div>
    @endif
  </form>
</div>
@endsection

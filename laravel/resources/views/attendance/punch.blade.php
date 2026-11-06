@extends('layouts.base')
@section('title', 'My Attendance')
@section('body_class', 'bg-slate-50')
@section('body')
<div class="mx-auto min-h-screen max-w-md px-4 py-6">
  <div class="mb-5 rounded-2xl bg-navy-800 p-4">@include('partials.logo', ['dark' => true])</div>

  <div class="card">
    <div class="card-body text-center">
      <h1 class="text-xl font-bold text-navy-800">{{ $driver->name }}</h1>
      <p class="mt-1 text-sm text-slate-500">{{ now()->format('l, d F Y') }}</p>

      @if (session('success'))<div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">{{ session('success') }}</div>@endif
      @if (session('error'))<div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{{ session('error') }}</div>@endif

      <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Punch in</dt><dd class="mt-1 text-lg font-bold text-navy-800">{{ \App\Models\DriverAttendance::timeLabel($today?->punch_in) ?: '—' }}</dd></div>
        <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Punch out</dt><dd class="mt-1 text-lg font-bold text-navy-800">{{ \App\Models\DriverAttendance::timeLabel($today?->punch_out) ?: '—' }}</dd></div>
      </dl>

      <div class="mt-5">
        @if ($today && in_array($today->status, \App\Models\DriverAttendance::NOT_WORKING, true))
          <p class="rounded-xl bg-slate-100 px-4 py-4 text-sm font-medium text-slate-700">You are marked {{ $today->statusLabel() }} today. Please contact the office if this is wrong.</p>
        @elseif (! $today?->punch_in)
          <form method="post" action="{{ route('driver.punch', $token) }}"><input type="hidden" name="action" value="in"><button class="btn-primary w-full !py-4 !text-lg"><i data-lucide="log-in"></i> Punch In</button></form>
        @elseif (! $today->punch_out)
          <form method="post" action="{{ route('driver.punch', $token) }}" data-confirm="Punch out now? You cannot punch in again today."><input type="hidden" name="action" value="out"><button class="btn-navy w-full !py-4 !text-lg"><i data-lucide="log-out"></i> Punch Out</button></form>
        @else
          <p class="rounded-xl bg-emerald-50 px-4 py-4 text-sm font-semibold text-emerald-800">Today's attendance is complete. Worked {{ $today->workedLabel() }}.</p>
        @endif
      </div>
      <p class="mt-4 text-xs text-slate-500">The time is recorded automatically when you tap the button.</p>
    </div>
  </div>

  @if ($recent->isNotEmpty())
  <div class="card mt-5">
    <div class="card-header"><h2 class="card-title">Recent days</h2></div>
    <ul class="divide-y divide-slate-100 text-sm">
      @foreach ($recent as $r)
      <li class="flex items-center justify-between gap-3 px-5 py-3">
        <span>{{ $r->date->format('d M') }}<span class="block text-xs text-slate-500">{{ $r->statusLabel() }}</span></span>
        <span class="text-right text-xs text-slate-600">{{ \App\Models\DriverAttendance::timeLabel($r->punch_in) ?: '—' }} – {{ \App\Models\DriverAttendance::timeLabel($r->punch_out) ?: '—' }}</span>
      </li>
      @endforeach
    </ul>
  </div>
  @endif
  <p class="mt-5 text-center text-xs text-slate-500">This link is only for you. Please do not share it.</p>
</div>
@endsection

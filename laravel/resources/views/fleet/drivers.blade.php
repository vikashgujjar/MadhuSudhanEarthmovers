@extends('layouts.app')
@section('title', 'Drivers')
@section('header_title', 'Drivers')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Drivers</h1><p class="page-subtitle">Operators, assigned machines, salary and allowance details.</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    @can('manage_masters')<a href="{{ route('drivers.create') }}" class="btn-primary"><i data-lucide="user-plus"></i> Add driver</a>@endcan
  </div>
</div>
<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, licence…" class="form-input sm:max-w-xs">
    <select name="vehicle" class="form-input sm:w-56"><option value="">Any vehicle</option>@foreach ($vehicles as $v)<option value="{{ $v->id }}" @selected(request('vehicle') === (string) $v->id)>{{ $v }}</option>@endforeach</select>
    <select name="status" class="form-input sm:w-40"><option value="">Any status</option>@foreach (\App\Models\Driver::STATUSES as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>
    <button class="btn-navy"><i data-lucide="search"></i> Filter</button>
    @if (request()->query())<a href="{{ route('drivers.index') }}" class="btn-ghost">Clear</a>@endif
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Driver</th><th>Licence</th><th>Assigned vehicle</th><th class="num">Monthly salary</th><th class="num">Daily allowance</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      @forelse ($drivers as $d)
      <tr>
        <td><a href="{{ route('drivers.show', $d) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $d->name }}</a><div class="text-xs text-slate-500">{{ $d->phone ?: 'No phone' }}</div></td>
        <td>{{ $d->license_number ?: '—' }}@if ($d->license_expiry)<div class="text-xs text-slate-500">Expires {{ $d->license_expiry->format('d M Y') }}</div>@endif</td>
        <td>@if ($d->assignedVehicle)<a href="{{ route('vehicles.show', $d->assignedVehicle) }}" class="hover:text-brand-600">{{ $d->assignedVehicle->displayName() }}</a>@else<span class="text-slate-400">—</span>@endif</td>
        <td class="num">{{ inr($d->monthly_salary) }}</td>
        <td class="num">{{ inr($d->daily_allowance) }}</td>
        <td><span class="{{ status_badge($d->status) }}">{{ $d->statusLabel() }}</span></td>
        <td><div class="flex justify-end gap-1">
          <a href="{{ route('drivers.show', $d) }}" class="btn-ghost btn-sm">View</a>
          @can('manage_masters')<a href="{{ route('drivers.edit', $d) }}" class="btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>@endcan
        </div></td>
      </tr>
      @empty
      <tr><td colspan="7" class="py-12 text-center text-slate-500">No drivers found.@can('manage_masters') <a href="{{ route('drivers.create') }}" class="font-semibold text-brand-600">Add a driver</a>.@endcan</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $drivers->links() }}
</div>
@endsection

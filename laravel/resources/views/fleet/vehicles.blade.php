@extends('layouts.app')
@section('title', 'Vehicles')
@section('header_title', 'All Vehicles')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">All vehicles</h1><p class="page-subtitle">Every JCB and tractor registered with {{ $business->business_name }}.</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    @can('manage_masters')<a href="{{ route('vehicles.create') }}" class="btn-primary"><i data-lucide="plus"></i> Add vehicle</a>@endcan
  </div>
</div>
<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Registration, name, make…" class="form-input sm:max-w-xs">
    <select name="category" class="form-input sm:w-40"><option value="">All categories</option>@foreach (\App\Models\Vehicle::CATEGORIES as $v => $l)<option value="{{ $v }}" @selected(request('category') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="status" class="form-input sm:w-48"><option value="">Any status</option>@foreach (\App\Models\Vehicle::STATUSES as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>
    <button class="btn-navy"><i data-lucide="search"></i> Filter</button>
    @if (request()->query())<a href="{{ route('vehicles.index') }}" class="btn-ghost">Clear</a>@endif
  </form>
  @include('fleet._vehicle-table')
  {{ $vehicles->links() }}
</div>
@endsection

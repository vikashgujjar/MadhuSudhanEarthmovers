@extends('layouts.app')
@section('title', $driver->exists ? 'Edit driver' : 'Add driver')
@section('header_title', 'Drivers')
@section('content')
@if ($driver->exists)
  @include('partials.page-header', ['title' => 'Edit driver', 'subtitle' => $driver->name, 'icon' => 'id-card'])
@else
  @include('partials.page-header', ['title' => 'Add driver', 'subtitle' => 'Register an operator and assign a machine.', 'icon' => 'id-card'])
@endif
<form method="post" class="space-y-6" novalidate>
  @csrf
  @include('partials.form-errors')
  <div class="card">
    <div class="card-header"><h2 class="card-title">Personal &amp; licence</h2></div>
    <div class="card-body grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
      <x-field name="name" label="Name" :value="$driver->name" required />
      <x-field name="phone" label="Phone" :value="$driver->phone" />
      <x-field name="alternate_phone" label="Alternate phone" :value="$driver->alternate_phone" />
      <x-field name="license_number" label="Licence number" :value="$driver->license_number" />
      <x-field name="license_expiry" label="Licence expiry" type="date" :value="$driver->license_expiry" />
      <x-field name="status" label="Status" type="select" :options="\App\Models\Driver::STATUSES" :value="$driver->status" required />
      <x-field name="address" label="Address" type="textarea" :value="$driver->address" wrapper="sm:col-span-2 xl:col-span-3" />
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Assignment &amp; pay</h2></div>
    <div class="card-body grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
      <x-field name="assigned_vehicle" label="Assigned vehicle" type="select" empty="— Not assigned —"
        :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => (string) $v])->all()" :value="$driver->assigned_vehicle_id" />
      <x-field name="joining_date" label="Joining date" type="date" :value="$driver->joining_date" />
      <x-field name="monthly_salary" label="Monthly salary" type="number" :value="$driver->monthly_salary" required />
      <x-field name="daily_allowance" label="Daily allowance (bata)" type="number" :value="$driver->daily_allowance" required />
      <x-field name="notes" label="Notes" type="textarea" :value="$driver->notes" wrapper="sm:col-span-2 xl:col-span-4" />
    </div>
  </div>
  <div class="flex justify-end gap-3">
    <a href="{{ $driver->exists ? route('drivers.show', $driver) : route('drivers.index') }}" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><i data-lucide="save"></i> Save driver</button>
  </div>
</form>
@endsection

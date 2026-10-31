@extends('layouts.app')
@section('title', $vehicle->exists ? 'Edit vehicle' : 'Add vehicle')
@section('header_title', 'Vehicles')
@section('content')
@if ($vehicle->exists)
  @include('partials.page-header', ['title' => 'Edit vehicle', 'subtitle' => $vehicle->registration_number, 'icon' => 'truck'])
@else
  @include('partials.page-header', ['title' => 'Register vehicle', 'subtitle' => 'Add a JCB or tractor to the fleet.', 'icon' => 'truck'])
@endif
<form method="post" class="space-y-6" novalidate>
  @csrf
  @include('partials.form-errors')
  <div class="card">
    <div class="card-header"><h2 class="card-title">Identification</h2></div>
    <div class="card-body grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
      <x-field name="category" label="Category" type="select" :options="\App\Models\Vehicle::CATEGORIES" empty="Select category" :value="$vehicle->category" required />
      <x-field name="registration_number" label="Registration number" :value="$vehicle->registration_number" required placeholder="e.g. KA 01 AB 1234" />
      <x-field name="name" label="Name" :value="$vehicle->name" placeholder="e.g. JCB-1" help="Short name used on site." />
      <x-field name="status" label="Status" type="select" :options="\App\Models\Vehicle::STATUSES" :value="$vehicle->status" required />
      <x-field name="make" label="Make" :value="$vehicle->make" placeholder="e.g. JCB, Mahindra, Swaraj" />
      <x-field name="model" label="Model" :value="$vehicle->model" />
      <x-field name="year" label="Year" type="number" step="1" :value="$vehicle->year" />
      <x-field name="insurance_expiry" label="Insurance expiry" type="date" :value="$vehicle->insurance_expiry" />
      <x-field name="chassis_number" label="Chassis number" :value="$vehicle->chassis_number" />
      <x-field name="engine_number" label="Engine number" :value="$vehicle->engine_number" />
      <x-field name="purchase_date" label="Purchase date" type="date" :value="$vehicle->purchase_date" />
      <x-field name="purchase_cost" label="Purchase cost" type="number" :value="$vehicle->purchase_cost" />
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Default billing rates</h2><p class="text-xs text-slate-500">Pre-filled on income entries; can be changed per job.</p></div>
    <div class="card-body grid gap-5 sm:grid-cols-2">
      <x-field name="hourly_rate" label="Hourly rate" type="number" :value="$vehicle->hourly_rate" required help="Default billing rate per hour." />
      <x-field name="daily_rate" label="Daily rate" type="number" :value="$vehicle->daily_rate" required help="Default billing rate per day." />
      <x-field name="notes" label="Notes" type="textarea" :value="$vehicle->notes" wrapper="sm:col-span-2" />
    </div>
  </div>
  <div class="flex justify-end gap-3">
    <a href="{{ $vehicle->exists ? route('vehicles.show', $vehicle) : route('vehicles.index') }}" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><i data-lucide="save"></i> Save vehicle</button>
  </div>
</form>
@endsection

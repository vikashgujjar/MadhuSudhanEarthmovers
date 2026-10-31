@extends('layouts.app')
@section('title', $driverMode ? 'Driver expense' : 'Expense')
@section('header_title', $driverMode ? 'Driver Expense Management' : 'Expense Management')
@section('content')
@if ($driverMode)
  @if ($expense->exists)
    @include('partials.page-header', ['title' => 'Edit driver expense', 'subtitle' => $expense->number, 'icon' => 'hard-hat'])
  @else
    @include('partials.page-header', ['title' => 'Add driver expense', 'subtitle' => 'Salary, advance, bata, food, travel — linked to the driver and, where applicable, the vehicle.', 'icon' => 'hard-hat'])
  @endif
@else
  @if ($expense->exists)
    @include('partials.page-header', ['title' => 'Edit expense', 'subtitle' => $expense->number, 'icon' => 'receipt'])
  @else
    @include('partials.page-header', ['title' => 'Add expense', 'subtitle' => 'Select the expense type and vehicle category first, then the vehicle.', 'icon' => 'receipt'])
  @endif
@endif
@php($category = old('vehicle_category', $expense->vehicle_category))
@php($vehicleOptions = $vehicles->mapWithKeys(fn ($v) => [$v->id => $v->optionLabel()])->all())
<form method="post" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-3" novalidate>
  @csrf
  <div class="xl:col-span-3">@include('partials.form-errors')</div>
  <div class="space-y-6 xl:col-span-2">
    <div class="card">
      <div class="card-header"><h2 class="card-title">{{ $driverMode ? 'Driver & vehicle' : 'Expense type & vehicle' }}</h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        @if ($driverMode)
          <x-field name="driver" label="Driver" type="select" required empty="Select driver" :options="$drivers->mapWithKeys(fn ($d) => [$d->id => (string) $d])->all()" :value="$prefill['driver'] ?? $expense->driver_id" />
          <x-field name="driver_expense_type" label="Driver expense type" type="select" required empty="Select type" :options="\App\Models\Expense::DRIVER_TYPES" :value="$expense->driver_expense_type" />
        @else
          <x-field name="category" label="Expense type" type="select" required empty="Select expense type" wrapper="sm:col-span-2"
            :options="collect(\App\Models\Expense::CATEGORIES)->except('DRIVER')->all()" :value="$expense->category" />
        @endif
        <x-field name="vehicle_category" label="Vehicle category" type="select" empty="General (no vehicle)" :options="\App\Models\Vehicle::CATEGORIES"
          :value="$expense->vehicle_category" data-vehicle-category data-vehicle-target="#id_vehicle" :data-vehicle-url="route('api.vehicles')"
          :help="$driverMode ? 'Optional: the machine the driver was working on.' : null" />
        <x-field name="vehicle" label="Vehicle" type="select" :empty="$category ? 'Select vehicle' : '— No vehicle (general) —'" :options="$vehicleOptions"
          :value="$prefill['vehicle'] ?? $expense->vehicle_id" data-allow-empty-category />
        @unless ($driverMode)
          <x-field name="driver" label="Driver" type="select" empty="— Not specified —" :options="$drivers->mapWithKeys(fn ($d) => [$d->id => (string) $d])->all()"
            :value="$prefill['driver'] ?? $expense->driver_id" help="Optional: who incurred it (e.g. driver who filled diesel)." />
          <x-field name="litres" label="Diesel quantity (litres)" type="number" :value="$expense->litres" show-when="#id_category" show-values="DIESEL" />
        @endunless
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Payment details</h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-field name="date" label="Date" type="date" :value="$expense->date" required />
        <x-field name="amount" label="Amount" type="number" :value="$expense->amount" required />
        <x-field name="payment_mode" label="Payment mode" type="select" :options="\App\Models\Payment::MODES" :value="$expense->payment_mode" required />
        <x-field name="vendor" label="Vendor" :value="$expense->vendor" placeholder="Fuel station, workshop, shop…" />
        <x-field name="bill_number" label="Bill number" :value="$expense->bill_number" />
        <x-field name="attachment" label="Bill / receipt (optional)" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" :help="$expense->attachment ? 'A file is attached; choose a new one only to replace it.' : 'PDF, JPG, PNG or WebP, up to 5 MB.'" />
        <x-field name="description" label="Description" type="textarea" rows="2" :value="$expense->description" wrapper="sm:col-span-2" />
      </div>
    </div>
  </div>
  <div class="space-y-4">
    <div class="card card-body text-sm text-slate-600">
      <p class="form-section mb-2">How this is counted</p>
      <ul class="list-disc space-y-1 pl-5 text-xs leading-relaxed">
        <li>Diesel, maintenance, repairs and spare parts must be linked to a JCB or tractor.</li>
        <li>Driver expenses are linked to a driver and optionally to the vehicle they worked on.</li>
        <li>Every expense is counted <strong>once</strong> in total business expenses — linking it to a vehicle or driver only assigns it, it never duplicates it.</li>
        <li>Expenses without a vehicle are shown as general overheads.</li>
      </ul>
    </div>
    @if (! $user->isAdmin() && $business->require_approval)<p class="rounded-lg bg-navy-50 px-4 py-3 text-xs text-navy-700"><i data-lucide="info" class="mr-1 inline h-3.5 w-3.5"></i> This entry will be sent to an administrator for approval.</p>@endif
    <button class="btn-primary w-full"><i data-lucide="save"></i> Save expense</button>
    @unless ($expense->exists)<button name="save_and_add" value="1" class="btn-secondary w-full"><i data-lucide="copy-plus"></i> Save &amp; add another</button>@endunless
    <a href="{{ $expense->exists ? route('expenses.show', $expense->id) : ($driverMode ? route('driver-expenses.index') : route('expenses.index')) }}" class="btn-ghost w-full">Cancel</a>
  </div>
</form>
@endsection

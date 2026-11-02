@extends('layouts.app')
@section('title', $income->exists ? 'Edit income' : 'Add income')
@section('header_title', 'Income Management')
@section('content')
@if ($income->exists)
  @include('partials.page-header', ['title' => 'Edit income', 'subtitle' => $income->number, 'icon' => 'trending-up'])
@else
  @include('partials.page-header', ['title' => 'Add income', 'subtitle' => 'Select the vehicle category first, then the machine that did the work.', 'icon' => 'trending-up'])
@endif
@php($category = old('vehicle_category', $income->vehicle_category))
<form method="post" data-income-form class="grid gap-6 xl:grid-cols-3" novalidate>
  @csrf
  <div class="xl:col-span-3">@include('partials.form-errors')</div>
  <div class="space-y-6 xl:col-span-2">
    <div class="card">
      <div class="card-header"><h2 class="card-title"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">1</span>Vehicle &amp; customer</h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-field name="vehicle_category" label="Vehicle category" type="select" :options="\App\Models\Vehicle::CATEGORIES" empty="Select category"
          :value="$income->vehicle_category" required data-vehicle-category data-vehicle-target="#id_vehicle" :data-vehicle-url="route('api.vehicles')" />
        <x-field name="vehicle" label="Vehicle" type="select" required :empty="$category ? 'Select vehicle' : 'Select a vehicle category first'"
          :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => ['label' => $v->optionLabel(), 'data' => ['hourly-rate' => $v->hourly_rate, 'daily-rate' => $v->daily_rate]]])->all()"
          :value="$prefill['vehicle'] ?? $income->vehicle_id" />
        <div>
          <x-field name="customer" label="Customer" type="select" required empty="Select customer"
            :options="$customers->pluck('name', 'id')->all()" :value="$prefill['customer'] ?? $income->customer_id" />
          @can('manage_masters')<a href="{{ route('customers.create', ['next' => request()->getRequestUri()]) }}" class="mt-1 inline-block text-xs font-semibold text-brand-600">+ New customer</a>@endcan
        </div>
        <x-field name="driver" label="Driver" type="select" empty="— Not specified —" :options="$drivers->mapWithKeys(fn ($d) => [$d->id => (string) $d])->all()" :value="$prefill['driver'] ?? $income->driver_id" />
        <x-field name="date" label="Date" type="date" :value="$income->date" required />
        <x-field name="work_location" label="Work location" :value="$income->work_location" placeholder="Site / village / project" />
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-500 text-xs font-bold text-white">2</span>Billing</h2></div>
      <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-field name="billing_type" label="Billing type" type="select" :options="\App\Models\Income::BILLING_TYPES" :value="$income->billing_type" required wrapper="sm:col-span-2" />
        <x-field name="quantity" label="Hours / days worked" type="number" step="0.01" min="0" :value="$income->quantity" help="Hours as hours.minutes: 1.30 = 1 hr 30 min, 2.45 = 2 hr 45 min." show-when="#id_billing_type" show-values="HOURLY,DAILY" />
        <x-field name="rate" label="Rate" type="number" :value="$income->rate" help="Per hour or per day; pre-filled from the vehicle." show-when="#id_billing_type" show-values="HOURLY,DAILY" />
        <x-field name="amount" label="Billed amount" type="number" :value="$income->amount" help="Calculated automatically for hourly and daily billing." wrapper="sm:col-span-2" />
        <x-field name="description" label="Description" type="textarea" rows="2" :value="$income->description" wrapper="sm:col-span-2" />
      </div>
    </div>
  </div>
  <div class="space-y-6">
    <div class="card overflow-hidden">
      <div class="bg-navy-800 px-5 py-4 text-white">
        <p class="text-xs font-semibold uppercase tracking-wider text-navy-200">Billed amount</p>
        <p class="mt-1 text-3xl font-bold tabular" data-amount-preview>₹ 0.00</p>
      </div>
      @if ($withPayment)
      <div class="card-body space-y-4">
        <p class="form-section">Payment received now</p>
        <x-field name="received_now" label="Amount received now" type="number" help="Leave empty if nothing was paid yet. Partial amounts are allowed." />
        <x-field name="payment_mode" label="Received via" type="select" :options="\App\Models\Payment::MODES" value="CASH" />
        <x-field name="payment_reference" label="Payment reference" placeholder="UTR / cheque no." />
        <div class="flex items-center justify-between rounded-lg bg-amber-50 px-3 py-2 text-sm"><span class="text-amber-800">Balance to collect</span><span class="font-bold tabular text-amber-800" data-balance-preview>₹ 0.00</span></div>
      </div>
      @else
      <div class="card-body text-sm text-slate-500">Payments for this invoice are managed on the invoice page.</div>
      @endif
    </div>
    @if (! $user->isAdmin() && $business->require_approval)<p class="rounded-lg bg-navy-50 px-4 py-3 text-xs text-navy-700"><i data-lucide="info" class="mr-1 inline h-3.5 w-3.5"></i> This entry will be sent to an administrator for approval.</p>@endif
    <div class="flex flex-col gap-2">
      <button class="btn-primary w-full"><i data-lucide="save"></i> Save income</button>
      @unless ($income->exists)<button name="save_and_add" value="1" class="btn-secondary w-full"><i data-lucide="copy-plus"></i> Save &amp; add another</button>@endunless
      <a href="{{ $income->exists ? route('income.show', $income->id) : route('income.index') }}" class="btn-ghost w-full">Cancel</a>
    </div>
  </div>
</form>
@endsection

@extends('layouts.app')
@section('title', $payment->exists ? 'Edit payment' : 'Record payment')
@section('header_title', 'Payment Tracking')
@section('content')
@if ($payment->exists)
  @include('partials.page-header', ['title' => 'Edit payment', 'subtitle' => $payment->number, 'icon' => 'wallet'])
@else
  @include('partials.page-header', ['title' => 'Record payment', 'subtitle' => 'Money received from a customer — full, partial or advance.', 'icon' => 'wallet'])
@endif
<form method="post" data-payment-form class="grid gap-6 xl:grid-cols-3" novalidate>
  @csrf
  <div class="xl:col-span-3">@include('partials.form-errors')</div>
  <div class="card xl:col-span-2">
    <div class="card-body grid gap-5 sm:grid-cols-2">
      <x-field name="customer" label="Customer" type="select" required empty="Select customer" :options="$customers->pluck('name', 'id')->all()" :value="$prefill['customer'] ?? $payment->customer_id" />
      <div>
        <x-field name="income" label="Against invoice" type="select" empty="— Advance / on-account (no invoice) —"
          :options="$invoices->mapWithKeys(fn ($i) => [$i->id => $i->number.' · '.$i->date->format('d M Y').' · billed '.$i->amount])->all()"
          :value="$prefill['income'] ?? $payment->income_id" :data-invoices-url="route('api.invoices')" :data-include="$payment->income_id ?? ''" />
        <p class="form-help font-medium text-navy-700" data-due-hint></p>
      </div>
      <x-field name="amount" label="Amount received" type="number" :value="$payment->amount" required />
      <x-field name="date" label="Date" type="date" :value="$payment->date" required />
      <x-field name="mode" label="Received via" type="select" :options="\App\Models\Payment::MODES" :value="$payment->mode" required />
      <x-field name="reference" label="Reference" :value="$payment->reference" placeholder="UTR / cheque no." help="UTR, cheque or transaction number." />
      <x-field name="notes" label="Notes" type="textarea" rows="2" :value="$payment->notes" wrapper="sm:col-span-2" />
    </div>
  </div>
  <div class="space-y-4">
    <div class="card card-body text-xs leading-relaxed text-slate-600">
      <p class="form-section mb-2">Partial payments</p>
      Record each instalment separately against the same invoice. The invoice balance and the customer's outstanding update automatically once approved. A payment can never exceed the invoice's remaining balance; use "Advance / on-account" for money received before billing.
    </div>
    @if (! $user->isAdmin() && $business->require_approval)<p class="rounded-lg bg-navy-50 px-4 py-3 text-xs text-navy-700"><i data-lucide="info" class="mr-1 inline h-3.5 w-3.5"></i> This payment will be sent to an administrator for approval.</p>@endif
    <button class="btn-primary w-full"><i data-lucide="save"></i> Save payment</button>
    <a href="{{ $payment->exists ? route('payments.show', $payment->id) : route('payments.index') }}" class="btn-ghost w-full">Cancel</a>
  </div>
</form>
@endsection

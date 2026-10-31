@extends('layouts.app')
@section('title', $customer->exists ? 'Edit customer' : 'Add customer')
@section('header_title', 'Customers')
@section('content')
@if ($customer->exists)
  @include('partials.page-header', ['title' => 'Edit customer', 'subtitle' => $customer->name, 'icon' => 'users'])
@else
  @include('partials.page-header', ['title' => 'Add customer', 'subtitle' => 'Create a customer account for billing and collections.', 'icon' => 'user-plus'])
@endif
<form method="post" class="card" novalidate>
  @csrf
  @if (request('next'))<input type="hidden" name="next" value="{{ request('next') }}">@endif
  <div class="card-body space-y-5">
    @include('partials.form-errors')
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
      <x-field name="name" label="Name" :value="$customer->name" required />
      <x-field name="contact_person" label="Contact person" :value="$customer->contact_person" />
      <x-field name="phone" label="Phone" :value="$customer->phone" />
      <x-field name="email" label="Email" type="email" :value="$customer->email" />
      <x-field name="gstin" label="GSTIN" :value="$customer->gstin" />
      <x-field name="opening_balance" label="Opening balance" type="number" :value="$customer->opening_balance" required help="Amount the customer already owed before using this ERP." />
      <x-field name="address" label="Address" type="textarea" :value="$customer->address" wrapper="sm:col-span-2 xl:col-span-3" />
      <x-field name="notes" label="Notes" type="textarea" :value="$customer->notes" wrapper="sm:col-span-2 xl:col-span-3" />
      <x-field name="is_active" label="Active" type="checkbox" :value="$customer->is_active" wrapper="sm:col-span-2 xl:col-span-3" />
    </div>
  </div>
  <div class="flex justify-end gap-3 border-t border-slate-100 px-5 py-4">
    <a href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><i data-lucide="save"></i> Save customer</button>
  </div>
</form>
@endsection

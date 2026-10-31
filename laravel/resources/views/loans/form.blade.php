@extends('layouts.app')
@section('title', $loan->exists ? 'Edit loan' : 'Lend money')
@section('header_title', 'Money Lent')
@section('content')
@if ($loan->exists)
  @include('partials.page-header', ['title' => 'Edit loan', 'subtitle' => $loan->number().' · '.$loan->borrower_name, 'icon' => 'hand-coins'])
@else
  @include('partials.page-header', ['title' => 'Lend money', 'subtitle' => 'Record money you gave someone, and when you expect it back.', 'icon' => 'hand-coins'])
@endif
<form method="post" class="card" novalidate>
  @csrf
  <div class="card-body space-y-5">
    @include('partials.form-errors')
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
      <x-field name="borrower_name" label="Lent to (name)" :value="$loan->borrower_name" required />
      <x-field name="phone" label="Phone" :value="$loan->phone" />
      <x-field name="amount" label="Amount lent" type="number" :value="$loan->amount" required />
      <x-field name="date" label="Date given" type="date" :value="$loan->date" required />
      <x-field name="due_date" label="Expected back by" type="date" :value="$loan->due_date" help="Leave empty if no date was agreed. Overdue loans are flagged on the dashboard." />
      <x-field name="mode" label="Given by" type="select" :options="\App\Models\Payment::MODES" :value="$loan->mode" required />
      <x-field name="address" label="Address" type="textarea" :value="$loan->address" rows="2" wrapper="sm:col-span-2 xl:col-span-3" />
      <x-field name="notes" label="Notes" type="textarea" :value="$loan->notes" rows="2" wrapper="sm:col-span-2 xl:col-span-3" placeholder="Purpose, witness, interest agreed…" />
    </div>
  </div>
  <div class="flex justify-end gap-3 border-t border-slate-100 px-5 py-4">
    <a href="{{ $loan->exists ? route('loans.show', $loan) : route('loans.index') }}" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><i data-lucide="save"></i> Save</button>
  </div>
</form>
@endsection

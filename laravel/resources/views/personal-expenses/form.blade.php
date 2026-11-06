@extends('layouts.app')
@section('title', $expense->exists ? 'Edit personal expense' : 'Add personal expense')
@section('header_title', 'Personal Expenses')
@section('content')
@if ($expense->exists)
  @include('partials.page-header', ['title' => 'Edit personal expense', 'subtitle' => $expense->number().' · '.$expense->categoryLabel(), 'icon' => 'user-round'])
@else
  @include('partials.page-header', ['title' => 'Add personal expense', 'subtitle' => 'The owner\'s own spending. Kept on record, never counted in business figures.', 'icon' => 'user-round'])
@endif
<form method="post" class="card" novalidate>
  @csrf
  <div class="card-body space-y-5">
    @include('partials.form-errors')
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
      <x-field name="date" label="Date" type="date" :value="$expense->date" required />
      <x-field name="category" label="Category" type="select" :options="\App\Models\PersonalExpense::CATEGORIES" empty="Select category" :value="$expense->category" required />
      <x-field name="amount" label="Amount" type="number" :value="$expense->amount" required />
      <x-field name="mode" label="Paid by" type="select" :options="\App\Models\Payment::MODES" :value="$expense->mode" required />
      <x-field name="paid_to" label="Paid to" :value="$expense->paid_to" placeholder="Shop, person, school, hospital…" />
      <x-field name="description" label="Description" :value="$expense->description" placeholder="What it was for" />
      <x-field name="notes" label="Notes" type="textarea" :value="$expense->notes" rows="2" wrapper="sm:col-span-2 xl:col-span-3" />
    </div>
  </div>
  <div class="flex flex-wrap justify-end gap-3 border-t border-slate-100 px-5 py-4">
    <a href="{{ $expense->exists ? route('personal-expenses.show', $expense) : route('personal-expenses.index') }}" class="btn-secondary">Cancel</a>
    @unless ($expense->exists)<button name="action" value="save_add" class="btn-secondary"><i data-lucide="copy-plus"></i> Save &amp; add another</button>@endunless
    <button class="btn-primary"><i data-lucide="save"></i> Save</button>
  </div>
</form>
@endsection

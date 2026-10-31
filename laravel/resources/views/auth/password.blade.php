@extends('layouts.app')
@section('title', 'Change password')
@section('content')
@include('partials.page-header', ['title' => 'Change password', 'subtitle' => 'Choose a strong password of at least 8 characters.', 'icon' => 'key-round'])
<form method="post" class="card max-w-xl" novalidate>
  @csrf
  <div class="card-body space-y-5">
    @include('partials.form-errors')
    <x-field name="old_password" label="Old password" type="password" required autocomplete="current-password" />
    <x-field name="password" label="New password" type="password" required autocomplete="new-password" help="At least 8 characters; not entirely numeric; not a common password." />
    <x-field name="password_confirmation" label="New password confirmation" type="password" required autocomplete="new-password" />
  </div>
  <div class="flex justify-end gap-3 border-t border-slate-100 px-5 py-4">
    <a href="{{ route('dashboard') }}" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><i data-lucide="save"></i> Update password</button>
  </div>
</form>
@endsection

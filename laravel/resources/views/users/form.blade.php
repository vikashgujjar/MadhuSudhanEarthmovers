@extends('layouts.app')
@section('title', $target->exists ? 'Edit user' : 'Add user')
@section('header_title', 'Users & Permissions')
@section('content')
@if ($target->exists)
  @include('partials.page-header', ['title' => 'Edit user', 'subtitle' => $target->username, 'icon' => 'user-cog'])
@else
  @include('partials.page-header', ['title' => 'Add user', 'subtitle' => 'Create a login for a staff member.', 'icon' => 'user-plus'])
@endif

<form method="post" class="grid gap-6 xl:grid-cols-3" novalidate>
  @csrf
  <div class="xl:col-span-3">@include('partials.form-errors')</div>
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Account details</h2></div>
    <div class="card-body grid gap-5 sm:grid-cols-2">
      <x-field name="username" label="Username" :value="$target->username" required help="Letters, digits and @ . + - _ only." />
      <x-field name="role" label="Role" type="select" :options="\App\Models\User::ROLES" :value="$target->role" required />
      <x-field name="first_name" label="First name" :value="$target->first_name" required />
      <x-field name="last_name" label="Last name" :value="$target->last_name" />
      <x-field name="email" label="Email address" type="email" :value="$target->email" />
      <x-field name="phone" label="Phone" :value="$target->phone" />
      @unless ($target->exists)
        <x-field name="password" label="Password" type="password" required autocomplete="new-password" help="At least 8 characters; not entirely numeric; not a common password." />
        <x-field name="password_confirmation" label="Password confirmation" type="password" required autocomplete="new-password" />
      @else
        <x-field name="is_active" label="Account active (can sign in)" type="checkbox" :value="$target->is_active" wrapper="sm:col-span-2" />
      @endunless
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h2 class="card-title">Permissions</h2></div>
    <div class="card-body space-y-3">
      <div class="rounded-lg bg-navy-50 p-3 text-xs leading-relaxed text-navy-700">
        <strong>Admin</strong>: every permission, always. <strong>Viewer</strong>: can never add or change data; only the reporting/export permissions apply. <strong>Entry user</strong>: only what is ticked below. All permissions are enforced on the server.
      </div>
      @foreach (\App\Models\User::CAPABILITIES as $key => $label)
        <x-field name="can_{{ $key }}" :label="$label" type="checkbox" :value="$target->getAttribute('can_'.$key)" />
      @endforeach
    </div>
  </div>
  <div class="flex justify-end gap-3 xl:col-span-3">
    <a href="{{ route('users.index') }}" class="btn-secondary">Cancel</a>
    <button type="submit" class="btn-primary"><i data-lucide="save"></i> Save user</button>
  </div>
</form>
@endsection

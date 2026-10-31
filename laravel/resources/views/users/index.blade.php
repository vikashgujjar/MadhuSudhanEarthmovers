@extends('layouts.app')
@section('title', 'Users & Permissions')
@section('header_title', 'Users & Permissions')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">Users &amp; Permissions</h1>
    <p class="page-subtitle">Admins have full control. Entry users add transactions they are permitted to. Viewers are read-only.</p>
  </div>
  <a href="{{ route('users.create') }}" class="btn-primary"><i data-lucide="user-plus"></i> Add user</a>
</div>

<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, username, phone…" class="form-input sm:max-w-xs">
    <select name="role" class="form-input sm:w-44">
      <option value="">All roles</option>
      @foreach (\App\Models\User::ROLES as $value => $label)<option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>@endforeach
    </select>
    <select name="status" class="form-input sm:w-40">
      <option value="">Any status</option>
      <option value="active" @selected(request('status') === 'active')>Active</option>
      <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
    </select>
    <button class="btn-navy"><i data-lucide="search"></i> Filter</button>
    @if (request()->query())<a href="{{ route('users.index') }}" class="btn-ghost">Clear</a>@endif
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>User</th><th>Role</th><th>Permissions</th><th>Status</th><th>Last login</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      @forelse ($users as $u)
        <tr>
          <td>
            <div class="font-semibold text-navy-800">{{ $u->fullName() ?: $u->username }}</div>
            <div class="text-xs text-slate-500">{{ '@'.$u->username }}@if ($u->phone) · {{ $u->phone }}@endif</div>
          </td>
          <td><span class="{{ ['ADMIN' => 'badge-orange', 'VIEWER' => 'badge-slate'][$u->role] ?? 'badge-navy' }}">{{ $u->roleLabel() }}</span></td>
          <td class="max-w-sm whitespace-normal">
            @if ($u->isAdmin())<span class="text-xs text-slate-500">Full access</span>@else
            <div class="flex flex-wrap gap-1">
              @foreach (\App\Models\User::CAPABILITIES as $key => $label)@if ($u->hasCapability($key))<span class="badge-slate">{{ $label }}</span>@endif @endforeach
            </div>
            @endif
          </td>
          <td>@if ($u->is_active)<span class="badge-green">Active</span>@else<span class="badge-red">Inactive</span>@endif</td>
          <td class="text-slate-500">{{ $u->last_login_at?->format('d M Y, H:i') ?? 'Never' }}</td>
          <td>
            <div class="flex justify-end gap-1">
              <a href="{{ route('users.edit', $u) }}" class="btn-secondary btn-sm"><i data-lucide="pencil"></i> Edit</a>
              <a href="{{ route('users.password', $u) }}" class="btn-secondary btn-sm"><i data-lucide="key-round"></i> Password</a>
              @if ($u->id !== auth()->id())
              <form method="post" action="{{ route('users.toggle', $u) }}" data-confirm="{{ $u->is_active ? 'Deactivate' : 'Activate' }} {{ $u->username }}?">@csrf
                <input type="hidden" name="next" value="{{ request()->getRequestUri() }}">
                <button class="btn-sm {{ $u->is_active ? 'btn-ghost text-red-600' : 'btn-success' }}">@if ($u->is_active)<i data-lucide="user-x"></i> Deactivate @else<i data-lucide="user-check"></i> Activate @endif</button>
              </form>
              @endif
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="py-12 text-center text-slate-500">No users match your filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $users->links() }}
</div>
@endsection

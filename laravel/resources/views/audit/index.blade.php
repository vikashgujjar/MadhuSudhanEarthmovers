@extends('layouts.app')
@section('title', 'Audit Logs')
@section('header_title', 'Audit Logs')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="page-title">Audit logs</h1>
    <p class="page-subtitle">Append-only history of logins, transaction changes, approvals, exports and settings. Entries cannot be edited or deleted.</p>
  </div>
  @include('partials.export-buttons')
</div>
<div class="card">
  <form method="get" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search record, user…" class="form-input lg:col-span-2">
    <select name="action" class="form-input"><option value="">All actions</option>@foreach (\App\Models\AuditLog::ACTIONS as $v => $l)<option value="{{ $v }}" @selected(request('action') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="model" class="form-input"><option value="">All records</option>@foreach ($models as $m)<option value="{{ $m }}" @selected(request('model') === $m)>{{ $m }}</option>@endforeach</select>
    <select name="user" class="form-input"><option value="">All users</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('user') === (string) $u->id)>{{ $u->username }}</option>@endforeach</select>
    <div class="flex gap-2"><button class="btn-navy flex-1"><i data-lucide="filter"></i> Filter</button>@if (request()->query())<a href="{{ route('audit.index') }}" class="btn-ghost">Clear</a>@endif</div>
    <label class="text-xs text-slate-500">From<input type="date" name="from" value="{{ request('from') }}" class="form-input mt-1"></label>
    <label class="text-xs text-slate-500">To<input type="date" name="to" value="{{ request('to') }}" class="form-input mt-1"></label>
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>Changes</th><th>IP</th><th></th></tr></thead>
      <tbody>
      @forelse ($logs as $log)
      <tr>
        <td class="text-slate-500">{{ $log->timestamp->format('d M Y, H:i:s') }}</td>
        <td class="font-medium text-navy-800">{{ $log->username ?: '—' }}</td>
        <td><span class="{{ in_array($log->action, ['DELETE', 'REJECT', 'LOGIN_FAILED'], true) ? 'badge-red' : (in_array($log->action, ['APPROVE', 'CREATE'], true) ? 'badge-green' : (in_array($log->action, ['UPDATE', 'SETTINGS'], true) ? 'badge-amber' : 'badge-slate')) }}">{{ $log->actionLabel() }}</span></td>
        <td class="max-w-xs truncate"><span class="text-xs text-slate-500">{{ $log->model_name }}</span> {{ $log->object_repr }}</td>
        <td class="max-w-xs truncate text-xs text-slate-500">{{ $log->changeSummary() }}</td>
        <td class="text-xs text-slate-500">{{ $log->ip_address }}</td>
        <td class="text-right"><a href="{{ route('audit.show', $log) }}" class="btn-ghost btn-sm">View</a></td>
      </tr>
      @empty
      <tr><td colspan="7" class="py-12 text-center text-slate-500">No audit entries match your filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $logs->links() }}
</div>
@endsection

@extends('layouts.app')
@section('title', 'Audit entry')
@section('header_title', 'Audit Logs')
@section('content')
@php($c = $log->changes ?? [])
@php($show = fn ($v) => $v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? 'Yes' : 'No') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v)))
<div class="mb-6"><a href="{{ route('audit.index') }}" class="text-sm font-semibold text-brand-600">← Back to audit logs</a></div>
@include('partials.page-header', ['title' => $log->actionLabel(), 'subtitle' => $log->object_repr, 'icon' => 'scroll-text'])
<div class="grid gap-6 xl:grid-cols-3">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Details</h2></div>
    <dl class="divide-y divide-slate-100 text-sm">
      <div class="flex justify-between px-5 py-3"><dt class="text-slate-500">Time</dt><dd class="font-medium">{{ $log->timestamp->format('d M Y, H:i:s') }}</dd></div>
      <div class="flex justify-between px-5 py-3"><dt class="text-slate-500">User</dt><dd class="font-medium">{{ $log->username ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-3"><dt class="text-slate-500">Record type</dt><dd class="font-medium">{{ $log->model_name ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-3"><dt class="text-slate-500">Record ID</dt><dd class="font-medium">{{ $log->object_id ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-3"><dt class="text-slate-500">IP address</dt><dd class="font-medium">{{ $log->ip_address ?: '—' }}</dd></div>
    </dl>
  </div>
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Recorded data</h2></div>
    @if (! empty($c['fields']))
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
      <tbody>@foreach ($c['fields'] as $name => $pair)<tr><td class="font-medium">{{ $name }}</td><td class="whitespace-normal text-red-700">{{ $show($pair[0] ?? null) }}</td><td class="whitespace-normal text-emerald-700">{{ $show($pair[1] ?? null) }}</td></tr>@endforeach</tbody>
    </table></div>
    @elseif (! empty($c['snapshot']))
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Field</th><th>Value</th></tr></thead>
      <tbody>@foreach ($c['snapshot'] as $name => $value)<tr><td class="font-medium">{{ $name }}</td><td class="whitespace-normal">{{ $show($value) }}</td></tr>@endforeach</tbody>
    </table></div>
    @elseif ($c)
    <dl class="divide-y divide-slate-100 text-sm">@foreach ($c as $k => $v)<div class="flex justify-between gap-4 px-5 py-3"><dt class="text-slate-500">{{ ucfirst($k) }}</dt><dd class="text-right font-medium">{{ $show($v) }}</dd></div>@endforeach</dl>
    @else
    <p class="px-5 py-10 text-center text-sm text-slate-500">No additional data recorded for this action.</p>
    @endif
  </div>
</div>
@endsection

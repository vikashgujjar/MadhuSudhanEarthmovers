@extends('layouts.app')
@section('title', 'Customers')
@section('header_title', 'Customers')
@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div><h1 class="page-title">Customers</h1><p class="page-subtitle">Contractors, farmers, builders and other clients{{ $showBalances ? ' with live account balances' : '' }}.</p></div>
  <div class="flex flex-wrap gap-2">
    @include('partials.export-buttons')
    @can('manage_masters')<a href="{{ route('customers.create') }}" class="btn-primary"><i data-lucide="user-plus"></i> Add customer</a>@endcan
  </div>
</div>
<div class="card">
  <form method="get" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, GSTIN…" class="form-input sm:max-w-xs">
    <select name="status" class="form-input sm:w-40"><option value="">All</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
    <button class="btn-navy"><i data-lucide="search"></i> Filter</button>
    @if (request()->query())<a href="{{ route('customers.index') }}" class="btn-ghost">Clear</a>@endif
  </form>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Customer</th><th>Contact</th>@if ($showBalances)<th class="num">Billed</th><th class="num">Received</th><th class="num">Balance</th>@endif<th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      @forelse ($customers as $c)
      <tr>
        <td><a href="{{ route('customers.show', $c) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $c->name }}</a>@if ($c->gstin)<div class="text-xs text-slate-500">GSTIN {{ $c->gstin }}</div>@endif</td>
        <td>{{ $c->contact_person }}<div class="text-xs text-slate-500">{{ $c->phone ?: '—' }}</div></td>
        @if ($showBalances)
        @php($bal = $c->balance())
        <td class="num">{{ inr($c->billed) }}</td>
        <td class="num">{{ inr($c->received) }}</td>
        <td class="num font-semibold {{ \App\Support\Money::isPositive($bal) ? 'text-amber-700' : (\App\Support\Money::isNegative($bal) ? 'text-emerald-700' : '') }}">{{ inr($bal) }}@if (\App\Support\Money::isNegative($bal)) <span class="text-xs font-normal">(advance)</span>@endif</td>
        @endif
        <td>@if ($c->is_active)<span class="badge-green">Active</span>@else<span class="badge-slate">Inactive</span>@endif</td>
        <td><div class="flex justify-end gap-1">
          <a href="{{ route('customers.show', $c) }}" class="btn-ghost btn-sm">{{ $showBalances ? 'Statement' : 'View' }}</a>
          @can('record_payment')<a href="{{ route('payments.create', ['customer' => $c->id]) }}" class="btn-secondary btn-sm"><i data-lucide="wallet"></i> Receive</a>@endcan
          @can('manage_masters')<a href="{{ route('customers.edit', $c) }}" class="btn-secondary btn-sm" aria-label="Edit {{ $c->name }}"><i data-lucide="pencil"></i></a>@endcan
        </div></td>
      </tr>
      @empty
      <tr><td colspan="7" class="py-12 text-center text-slate-500">No customers found.@can('manage_masters') <a class="font-semibold text-brand-600" href="{{ route('customers.create') }}">Add a customer</a>.@endcan</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  {{ $customers->links() }}
</div>
@endsection

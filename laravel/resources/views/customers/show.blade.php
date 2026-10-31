@extends('layouts.app')
@section('title', $customer->name)
@section('header_title', 'Customer statement')
@section('content')
<div class="no-print mb-4"><a href="{{ route('customers.index') }}" class="text-sm font-semibold text-brand-600">← Customers</a></div>
<div class="mb-6 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
  <div class="flex items-center gap-4">
    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-navy-800 text-xl font-bold text-brand-500">{{ mb_strtoupper(mb_substr($customer->name, 0, 1)) }}</div>
    <div><h1 class="page-title">{{ $customer->name }}</h1>
      <p class="page-subtitle">{{ $customer->contact_person }}@if ($customer->phone) · {{ $customer->phone }}@endif @if ($customer->gstin) · GSTIN {{ $customer->gstin }}@endif @unless ($customer->is_active) · <span class="badge-slate">Inactive</span>@endunless</p></div>
  </div>
  <div class="no-print flex flex-wrap gap-2">
    <button type="button" class="btn-secondary" data-print><i data-lucide="printer"></i> Print statement</button>
    @can('add_income')<a href="{{ route('income.create', ['customer' => $customer->id]) }}" class="btn-secondary"><i data-lucide="plus"></i> Income</a>@endcan
    @can('record_payment')<a href="{{ route('payments.create', ['customer' => $customer->id]) }}" class="btn-primary"><i data-lucide="wallet"></i> Record payment</a>@endcan
    @can('manage_masters')<a href="{{ route('customers.edit', $customer) }}" class="btn-secondary"><i data-lucide="pencil"></i> Edit</a>@endcan
    @can('admin')<a href="{{ route('customers.delete', $customer) }}" class="btn-ghost text-red-600" aria-label="Delete customer"><i data-lucide="trash-2"></i></a>@endcan
  </div>
</div>

@if ($showBalances)
<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <div class="kpi"><p class="kpi-label">Opening balance</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($totals['opening']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Total billed</p><p class="kpi-value">{{ $business->currency_symbol }}{{ inr($totals['debit']) }}</p></div>
  <div class="kpi"><p class="kpi-label">Total received</p><p class="kpi-value !text-emerald-700">{{ $business->currency_symbol }}{{ inr($totals['credit']) }}</p></div>
  <div class="kpi"><div class="absolute inset-x-0 top-0 h-1 bg-brand-500"></div><p class="kpi-label">{{ \App\Support\Money::isNegative($totals['closing']) ? 'Advance held' : 'Outstanding' }}</p><p class="kpi-value {{ \App\Support\Money::isPositive($totals['closing']) ? '!text-amber-700' : '' }}">{{ $business->currency_symbol }}{{ inr($totals['closing']) }}</p></div>
</div>

@if ($pendingIncome || $pendingPayments)
<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
  <i data-lucide="clock" class="mr-1 inline h-4 w-4"></i> {{ $pendingIncome }} invoice{{ plural($pendingIncome) }} and {{ $pendingPayments }} payment{{ plural($pendingPayments) }} for this customer are awaiting approval and are not yet in the balance.
</div>
@endif

<div class="grid gap-6 xl:grid-cols-3">
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">Account statement</h2><span class="text-xs text-slate-500">Approved entries</span></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>No.</th><th>Particulars</th><th class="num">Debit (billed)</th><th class="num">Credit (received)</th><th class="num">Balance</th></tr></thead>
      <tbody>
        <tr class="bg-slate-50/60"><td colspan="5" class="font-medium text-slate-600">Opening balance</td><td class="num font-semibold">{{ inr($totals['opening']) }}</td></tr>
        @forelse ($statement as $l)
        <tr>
          <td>{{ $l['date']->format('d M Y') }}</td>
          <td>@if ($l['obj']->isVisibleTo($user))<a href="{{ $l['url'] }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $l['number'] }}</a>@else<span class="font-semibold text-navy-800">{{ $l['number'] }}</span>@endif</td>
          <td class="max-w-xs whitespace-normal"><span class="{{ $l['kind'] === 'Invoice' ? 'badge-navy' : 'badge-green' }}">{{ $l['kind'] }}</span> <span class="text-slate-600">{{ $l['particulars'] }}</span></td>
          <td class="num">@if (! \App\Support\Money::isZero($l['debit'])){{ inr($l['debit']) }}@endif</td>
          <td class="num text-emerald-700">@if (! \App\Support\Money::isZero($l['credit'])){{ inr($l['credit']) }}@endif</td>
          <td class="num font-semibold">{{ inr($l['balance']) }}</td>
        </tr>
        @empty
        <tr><td colspan="6" class="py-10 text-center text-slate-500">No approved invoices or payments yet.</td></tr>
        @endforelse
      </tbody>
      <tfoot><tr><td colspan="3">Closing balance</td><td class="num">{{ inr($totals['debit']) }}</td><td class="num">{{ inr($totals['credit']) }}</td><td class="num">{{ inr($totals['closing']) }}</td></tr></tfoot>
    </table></div>
  </div>
  <div class="space-y-6">
    <div class="card">
      <div class="card-header"><h2 class="card-title">Unpaid invoices</h2></div>
      <ul class="divide-y divide-slate-100">
        @forelse ($openInvoices as $inv)
        <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
          <div>@if ($inv->isVisibleTo($user))<a href="{{ route('income.show', $inv->id) }}" class="font-semibold text-navy-800 hover:text-brand-600">{{ $inv->number }}</a>@else<span class="font-semibold text-navy-800">{{ $inv->number }}</span>@endif<div class="text-xs text-slate-500">{{ $inv->date->format('d M Y') }} · billed {{ inr0($inv->amount) }} · {{ $inv->paymentState() }}</div></div>
          <div class="text-right"><div class="font-bold tabular text-amber-700">{{ inr($inv->outstanding()) }}</div>
            @can('record_payment')<a href="{{ route('payments.create', ['customer' => $customer->id, 'income' => $inv->id]) }}" class="no-print text-xs font-semibold text-brand-600">Receive →</a>@endcan</div>
        </li>
        @empty
        <li class="px-5 py-8 text-center text-sm text-slate-500">No unpaid invoices.</li>
        @endforelse
      </ul>
    </div>
    <div class="card">
      <div class="card-header"><h2 class="card-title">Contact</h2></div>
      <dl class="divide-y divide-slate-100 text-sm">
        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Email</dt><dd>{{ $customer->email ?: '—' }}</dd></div>
        <div class="px-5 py-2.5"><dt class="text-slate-500">Address</dt><dd class="mt-1 whitespace-pre-line">{{ $customer->address ?: '—' }}</dd></div>
        @if ($customer->notes)<div class="px-5 py-2.5"><dt class="text-slate-500">Notes</dt><dd class="mt-1 whitespace-pre-line">{{ $customer->notes }}</dd></div>@endif
      </dl>
    </div>
  </div>
</div>
@else
<div class="grid gap-6 xl:grid-cols-3">
  <div class="card">
    <div class="card-header"><h2 class="card-title">Contact</h2></div>
    <dl class="divide-y divide-slate-100 text-sm">
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Phone</dt><dd>{{ $customer->phone ?: '—' }}</dd></div>
      <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Email</dt><dd>{{ $customer->email ?: '—' }}</dd></div>
      <div class="px-5 py-2.5"><dt class="text-slate-500">Address</dt><dd class="mt-1 whitespace-pre-line">{{ $customer->address ?: '—' }}</dd></div>
    </dl>
  </div>
  <div class="card xl:col-span-2">
    <div class="card-header"><h2 class="card-title">{{ $user->isViewer() ? 'Recent income' : 'My income entries for this customer' }}</h2></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>No.</th><th>Vehicle</th><th class="num">Amount</th><th>Status</th></tr></thead>
      <tbody>@forelse ($myIncome as $i)<tr><td>{{ $i->date->format('d M Y') }}</td><td><a href="{{ route('income.show', $i->id) }}" class="font-semibold text-navy-800">{{ $i->number }}</a></td><td>{{ $i->vehicle->displayName() }}</td><td class="num">{{ inr($i->amount) }}</td><td><span class="{{ status_badge($i->status) }}">{{ $i->statusLabel() }}</span></td></tr>@empty<tr><td colspan="5" class="py-8 text-center text-slate-500">No entries.</td></tr>@endforelse</tbody>
    </table></div>
  </div>
</div>
@endif
@endsection

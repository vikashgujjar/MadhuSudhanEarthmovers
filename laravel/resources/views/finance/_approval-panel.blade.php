{{-- Expects: $obj, $kind ('income'|'expense'|'payment'), $history --}}
<div class="card">
  <div class="card-header"><h2 class="card-title">Status &amp; approval</h2><span class="{{ status_badge($obj->status) }}">{{ $obj->statusLabel() }}</span></div>
  <dl class="divide-y divide-slate-100 text-sm">
    <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Entered by</dt><dd class="font-medium">{{ $obj->creator ?? '—' }}</dd></div>
    <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">Entered on</dt><dd class="font-medium">{{ $obj->created_at?->format('d M Y, H:i') }}</dd></div>
    @if ($obj->approver)<div class="flex justify-between px-5 py-2.5"><dt class="text-slate-500">{{ $obj->status === 'REJECTED' ? 'Rejected' : 'Approved' }} by</dt><dd class="font-medium">{{ $obj->approver }} · {{ $obj->approved_at?->format('d M Y, H:i') }}</dd></div>@endif
    @if ($obj->rejection_reason)<div class="px-5 py-2.5"><dt class="text-slate-500">Rejection reason</dt><dd class="mt-1 font-medium text-red-700">{{ $obj->rejection_reason }}</dd></div>@endif
  </dl>
  @if ($obj->status === 'PENDING')
    @can('admin')
    <div class="no-print space-y-3 border-t border-slate-100 p-5">
      <form method="post" action="{{ route('approvals.approve', [$kind, $obj->id]) }}">@csrf<input type="hidden" name="next" value="{{ request()->getPathInfo() }}">
        <button class="btn-success w-full"><i data-lucide="check"></i> Approve</button></form>
      <form method="post" action="{{ route('approvals.reject', [$kind, $obj->id]) }}" class="space-y-2">@csrf<input type="hidden" name="next" value="{{ request()->getPathInfo() }}">
        <input type="text" name="reason" required maxlength="255" class="form-input" placeholder="Reason for rejection (required)" aria-label="Reason for rejection">
        <button class="btn-secondary w-full text-red-600"><i data-lucide="x"></i> Reject</button></form>
    </div>
    @else
    <p class="border-t border-slate-100 px-5 py-4 text-xs text-amber-700">Waiting for an administrator to approve. Pending entries are not counted in reports.</p>
    @endcan
  @endif
</div>
@if ($user->isAdmin() && $history->isNotEmpty())
<div class="card">
  <div class="card-header"><h2 class="card-title">Change history</h2><a href="{{ route('audit.index', ['q' => $obj->number]) }}" class="text-xs font-semibold text-brand-600">Audit log →</a></div>
  <ol class="divide-y divide-slate-100 text-sm">
    @foreach ($history as $h)
    @php($nFields = count($h->changes['fields'] ?? []))
    <li class="px-5 py-2.5"><a href="{{ route('audit.show', $h) }}" class="flex justify-between gap-2 hover:text-brand-600"><span><span class="font-medium">{{ $h->actionLabel() }}</span> by {{ $h->username ?: 'system' }}@if ($nFields) · {{ $nFields }} field{{ plural($nFields) }}@endif</span><span class="text-xs text-slate-500">{{ $h->timestamp->format('d M, H:i') }}</span></a></li>
    @endforeach
  </ol>
</div>
@endif

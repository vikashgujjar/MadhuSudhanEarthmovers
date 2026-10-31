{{-- Auto-submitting period selector used in page headers. --}}
<form method="get" class="no-print flex flex-col gap-2 sm:flex-row sm:items-center">
  @include('partials.period-fields', ['autosubmit' => true])
  <button class="btn-navy {{ $period->key !== 'custom' ? 'hidden' : '' }}" data-period-custom>Apply</button>
</form>

{{-- Period selector fields (place inside a GET form). --}}
<select name="period" class="form-input sm:w-48" data-period-select @if ($autosubmit ?? false) data-autosubmit @endif aria-label="Period">
  @foreach (\App\Support\Period::CHOICES as $value => $label)<option value="{{ $value }}" @selected($period->key === $value)>{{ $label }}</option>@endforeach
</select>
<input type="date" name="from" value="{{ $period->startDate() }}" class="form-input sm:w-40 {{ $period->key !== 'custom' ? 'hidden' : '' }}" data-period-custom aria-label="From date">
<input type="date" name="to" value="{{ $period->endDate() }}" class="form-input sm:w-40 {{ $period->key !== 'custom' ? 'hidden' : '' }}" data-period-custom aria-label="To date">

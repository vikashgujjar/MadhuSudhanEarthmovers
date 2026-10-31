{{-- Search + period + status fields shared by transaction registers (inside a GET form). --}}
<input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $placeholder ?? 'Search…' }}" class="form-input lg:col-span-2">
@include('partials.period-fields')
<select name="status" class="form-input"><option value="">All statuses</option>@foreach (\App\Models\Income::STATUSES as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>

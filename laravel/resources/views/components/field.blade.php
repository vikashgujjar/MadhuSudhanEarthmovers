{{--
  One form field: label, input, help text and error.
  <x-field name="amount" label="Amount" type="number" :value="$model->amount" required help="…" />
  type: text|email|number|date|password|textarea|select|checkbox|file|hidden
  select: :options="[value => label]" empty="Placeholder"
  Extra attributes (data-*, step, readonly …) go on the input element.
--}}
@props([
    'name', 'label' => null, 'type' => 'text', 'value' => null, 'options' => [], 'empty' => null,
    'required' => false, 'help' => null, 'placeholder' => null, 'showWhen' => null, 'showValues' => null,
    'wrapper' => '', 'id' => null,
])
@php
    $id = $id ?? 'id_'.$name;
    $hasOld = session()->hasOldInput();
    if ($type === 'checkbox') {
        $current = $hasOld ? (bool) old($name) : (bool) $value;
    } elseif (in_array($type, ['password', 'file'], true)) {
        $current = null;
    } else {
        $current = $hasOld ? old($name) : $value;
        if ($current instanceof \Carbon\CarbonInterface) {
            $current = $current->format('Y-m-d');
        }
    }
    $error = $errors->first($name);
    $inputClass = 'form-input'.($error ? ' is-invalid' : '');
@endphp
@if ($type === 'hidden')
<input type="hidden" name="{{ $name }}" id="{{ $id }}" value="{{ $current }}" {{ $attributes }}>
@else
<div class="{{ $wrapper }}" @if ($showWhen) data-show-when="{{ $showWhen }}" data-show-values="{{ $showValues }}" @endif>
  @if ($type === 'checkbox')
    <input type="hidden" name="{{ $name }}" value="0">
    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm">
      <input type="checkbox" name="{{ $name }}" id="{{ $id }}" value="1" class="form-check mt-0.5" @checked($current) {{ $attributes }}>
      <span><span class="font-medium text-slate-700">{{ $label }}</span>@if ($help)<span class="block text-xs text-slate-500">{{ $help }}</span>@endif</span>
    </label>
  @else
    <label for="{{ $id }}" class="form-label">{{ $label }}@if ($required) <span class="text-brand-600">*</span>@endif</label>
    @if ($type === 'textarea')
      <textarea name="{{ $name }}" id="{{ $id }}" class="{{ $inputClass }}" rows="{{ $attributes->get('rows', 3) }}" @if ($placeholder) placeholder="{{ $placeholder }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes->except('rows') }}>{{ $current }}</textarea>
    @elseif ($type === 'select')
      <select name="{{ $name }}" id="{{ $id }}" class="{{ $inputClass }}" @if ($error) aria-invalid="true" @endif {{ $attributes }}>
        @if ($empty !== null)<option value="">{{ $empty }}</option>@endif
        @foreach ($options as $optValue => $optLabel)
          @if (is_array($optLabel))
            <option value="{{ $optValue }}" @selected((string) $current === (string) $optValue) @foreach ($optLabel['data'] ?? [] as $dk => $dv) data-{{ $dk }}="{{ $dv }}" @endforeach>{{ $optLabel['label'] }}</option>
          @else
            <option value="{{ $optValue }}" @selected((string) $current === (string) $optValue)>{{ $optLabel }}</option>
          @endif
        @endforeach
      </select>
    @else
      <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" class="{{ $inputClass }}"
        @if ($type !== 'file') value="{{ $current }}" @endif
        @if ($type === 'number') inputmode="decimal" step="{{ $attributes->get('step', '0.01') }}" @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif @if ($error) aria-invalid="true" @endif {{ $attributes->except('step') }}>
    @endif
    @if ($help)<p class="form-help">{{ $help }}</p>@endif
  @endif
  @if ($error)<p class="form-error"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>{{ $error }}</p>@endif
</div>
@endif

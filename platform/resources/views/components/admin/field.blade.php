@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null])
{{-- Form field wrapper: label, control (slot), hint and the validation message for `error` (defaults to `for`). --}}
<div {{ $attributes }}>
    @if ($label)
        <label class="wx-label" @if ($for) for="{{ $for }}" @endif>{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($hint)
        <p class="mt-1 text-theme-xs text-gray-500">{{ $hint }}</p>
    @endif
    @if ($error ?? $for)
        @error($error ?? $for)
            <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>
        @enderror
    @endif
</div>

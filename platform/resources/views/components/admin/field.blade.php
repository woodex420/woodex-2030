@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null])
{{--
    Form field wrapper: label, control (slot), hint and the validation message for `error` (defaults to `for`).

    One control: give `for`, and the control the same id — the label then names it.
    Several controls under one caption (a set of checkboxes, a type and an amount): leave `for` out. The field
    is then a named group, and each control inside names itself (its own label or aria-label).
    tests/Unit/AdminFormLabelsTest.php fails when a field with a single control has no `for`.
--}}
<div {{ $attributes }} @if ($label && ! $for) role="group" aria-label="{{ $label }}" @endif>
    @if ($label && $for)
        <label class="wx-label" for="{{ $for }}">{{ $label }}</label>
    @elseif ($label)
        {{-- The group already carries this caption as its name; shown, not read out twice. --}}
        <span class="wx-label" aria-hidden="true">{{ $label }}</span>
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

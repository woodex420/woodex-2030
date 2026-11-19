@props([
    'value',                // the row's key
    'label',                // "Select L-0042": which row this box chooses
    'model' => 'selected',  // the Livewire property that holds the chosen keys
])
{{-- The tick box that chooses one row of <x-admin.table>. What can be done with the chosen rows is the table's "bulk" slot.
     The label around the box is what a pointer hits: 24 px each way, at any width (WCAG 2.5.8). --}}
<td {{ $attributes->class(['py-3 pl-5']) }}>
    <label class="inline-flex size-6 items-center justify-center"><input type="checkbox" wire:model.live="{{ $model }}" value="{{ $value }}" class="rounded border-gray-300" aria-label="{{ $label }}"></label>
</td>

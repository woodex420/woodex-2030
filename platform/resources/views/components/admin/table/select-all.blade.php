@props([
    'ids' => [],            // the keys of the rows on this page
    'selected' => [],       // the chosen keys
    'model' => 'selected',  // the Livewire property that holds them
    'label' => 'Select all on this page',
])
{{--
    The tick box in the headings that chooses every row of the page, or none. The keys travel in a data- attribute
    and are read there by the script; nothing is printed into the script itself.

    Where the table is drawn as cards (below 640 px, cards="true") the headings are not drawn, and this box stands
    in a line above the cards with its words beside it. The words are for the eye only: the box already carries
    them as its name.
--}}
@php
    $keys = collect($ids)->map(fn ($id) => (string) $id)->values();
    $all = $keys->isNotEmpty() && count($selected) >= $keys->count();
@endphp
<th scope="col" {{ $attributes->class(['wx-select-all w-10 py-3 pl-5']) }}>
    <label class="inline-flex min-h-6 min-w-6 items-center justify-center gap-2">
        <input type="checkbox" class="rounded border-gray-300" aria-label="{{ $label }}"
               x-data x-on:change="$wire.set($el.dataset.model, $event.target.checked ? JSON.parse($el.dataset.ids) : [])"
               data-model="{{ $model }}" data-ids="{{ $keys->toJson() }}" @checked($all)>
        <span class="wx-card-only font-normal tracking-normal normal-case" aria-hidden="true">{{ $label }}</span>
    </label>
</th>

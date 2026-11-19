@props([
    'sort' => null,         // the list's own words for sorting by this column, each with the way it goes:
                            // ['newest' => 'descending', 'oldest' => 'ascending']. The first is what one press gives.
    'current' => null,      // how the list is sorted now (the list's own word)
    'model' => 'sort',      // the Livewire property that holds it
    'align' => 'left',      // left · right · center
    'label' => null,        // a column without words above it (tick boxes, actions) is named for a screen reader
])
@aware(['dense' => false])
{{--
    A heading of <x-admin.table>. With :sort it is a button: a press sorts the list by this column, a second press
    turns it round where the list can. The heading says how it sorts in three ways: aria-sort on the cell, an arrow
    for the eye, and a sentence for a screen reader. The list keeps its own words ("newest", "value"): nothing about
    how a list sorts moves into this file.

    The property and the word travel in data- attributes, not inside the script (tests/Feature/Security/
    StoredScriptTest.php: only numbers and fixed words may be printed into an attribute that is run).
--}}
@php
    $sorts = is_array($sort) ? $sort : [];
    $words = array_keys($sorts);

    foreach ($sorts as $way) {
        if (! in_array($way, ['ascending', 'descending'], true)) {
            throw new \InvalidArgumentException("A heading sorts ascending or descending, not [{$way}].");
        }
    }

    $active = $current !== null && array_key_exists((string) $current, $sorts);
    $way = $active ? $sorts[(string) $current] : 'none';
    $next = $words === [] ? null : (string) ($active ? $words[(array_search((string) $current, array_map('strval', $words), true) + 1) % count($words)] : $words[0]);
    $turns = $next !== null && ($next !== (string) $current);
    $says = $active ? 'Sorted '.$way.'.' : 'Not sorted by this column.';
    $says .= $turns ? ' Press to sort '.$sorts[$next].'.' : '';
    $pad = $dense ? 'px-5 py-2' : 'px-5 py-3';
@endphp
<th scope="col" @if ($sorts !== []) aria-sort="{{ $way }}" @endif
    {{ $attributes->class([$pad, 'font-medium', 'text-right' => $align === 'right', 'text-center' => $align === 'center']) }}>
    @if ($sorts !== [])
        <button type="button" x-data x-on:click="$wire.set($el.dataset.model, $el.dataset.sort)" data-model="{{ $model }}" data-sort="{{ $next }}"
                @class(['inline-flex items-center gap-1 rounded font-medium tracking-wide uppercase hover:text-gray-800 dark:hover:text-gray-200', 'text-gray-800 dark:text-gray-200' => $active, 'flex-row-reverse' => $align === 'right'])>
            <span>{{ $slot }}</span>
            <x-icon :name="$way === 'ascending' ? 'arrow-up' : ($way === 'descending' ? 'arrow-down' : 'arrow-down-up')" @class(['size-3.5 shrink-0', 'opacity-50' => ! $active]) />
            <span class="sr-only">{{ $says }}</span>
        </button>
    @elseif ($label)
        <span class="sr-only">{{ $label }}</span>
    @else
        {{ $slot }}
    @endif
</th>

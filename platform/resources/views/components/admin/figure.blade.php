@props([
    'figure',                       // App\Domain\System\Dashboard\Figure
    'as' => 'link',                 // "link": the slot is the link's words · "block": the slot is a whole tile
])
{{--
    A figure of the dashboard and the door to its rows (master list T-044; the owner's document 05 §7: "Every
    KPI must link to filtered records").

    <x-admin.figure :figure="$figure">Open the {{ $figure->counted() }}</x-admin.figure>

    What it carries, for the tests and the browser journey to read:
      data-figure        the figure's name, `widget.figure`;
      data-figure-count  how many records it counted;
      data-figure-rows   present where the link opens a list of exactly that many rows (the list says the same
                         number in its data-list-rows).
    Where the person may not open the list, there is no link: the words stand as text, and say so.
--}}
@if ($figure->href !== null)
    <a href="{{ $figure->href }}" wire:navigate data-figure="{{ $figure->key }}" data-figure-count="{{ $figure->count }}" @if ($figure->rows) data-figure-rows @endif
       {{ $attributes->class(['font-medium text-brand-600 hover:underline dark:text-brand-400' => $as === 'link']) }}>{{ $slot }}</a>
@else
    <span data-figure="{{ $figure->key }}" data-figure-count="{{ $figure->count }}" data-figure-access="none"
          {{ $attributes->class(['text-gray-500 dark:text-gray-400' => $as === 'link']) }}
          title="The list behind this figure is not open to your role">{{ $slot }}</span>
@endif

@props([
    'chips' => [],                  // what the list is cut to, in words: ['Sent 1 Nov – 20 Nov 2026', …]
])
{{--
    "Filtered: …  Clear" above a list that a figure of the dashboard opened (master list T-044).

    A figure links to the rows it counted, so the list arrives cut to them. This line says so in words, because
    a short list without a reason looks like missing records; and "Clear" gives the whole list back. The leads
    list has had such a line since the control view (?created=today, ?due=overdue); this is the same line for
    the lists that got their filters with T-044. Nothing is drawn while no such filter is on.

    "Clear" calls the list's own clearQuickFilters(), which takes these filters off and leaves the search and the
    other choices. The action's name is written here, not handed in: what stands in wire:click is run as script,
    and only a fixed word may stand there (tests/Feature/Security/StoredScriptTest.php).
--}}
@if ($chips !== [])
    <div {{ $attributes->merge(['class' => 'mb-4 flex flex-wrap items-center gap-2']) }} data-list-filtered>
        <span class="text-theme-xs text-gray-500 dark:text-gray-400">Filtered:</span>
        @foreach ($chips as $chip)
            <x-admin.badge color="navy" :dot="false">{{ $chip }}</x-admin.badge>
        @endforeach
        <button type="button" wire:click="clearQuickFilters" class="text-theme-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Clear</button>
    </div>
@endif

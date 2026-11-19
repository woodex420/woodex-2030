@props([
    'label',                        // the list's name: read out as the table's name, and used in the words of each state
    'rows' => null,                 // a paginator, a collection or an array; null when the screen decides the state itself
    'state' => null,                // null: "empty" or "success", from the rows · or one of the five, said by the screen
    'columns' => null,              // how many columns; counted from the headings when not given
    'selected' => null,             // the keys of the chosen rows, where rows can be chosen
    'model' => 'selected',          // the Livewire property that holds them
    'card' => true,                 // false where the screen already draws the card
    'dense' => false,               // tighter rows, for a short table inside a section
    'tableClass' => '',
    'emptyIcon' => 'inbox',
    'emptyTitle' => null,
    'emptyDescription' => null,
    'errorTitle' => null,
    'errorDescription' => 'Nothing was changed. Try again in a moment.',
    'retry' => true,                // the error state offers "Try again" (a Livewire refresh)
    'forbiddenTitle' => null,
    'forbiddenDescription' => 'Your role does not include it. If you need it for your work, ask the owner to change your role.',
])
{{--
    The one table of the admin (master list T-041; the owner's document 05 §6: "Tables with sort, filters,
    pagination, selection and bulk actions", and "Empty, loading, error, no-permission … states").

    <x-admin.table label="Quotations" :rows="$quotes">
        <x-slot:filters class="grid grid-cols-1 gap-3 md:grid-cols-4"> …search and choices… </x-slot:filters>
        <x-slot:bulk> …what can be done with every chosen row… </x-slot:bulk>
        <x-slot:head>
            <x-admin.table.heading :sort="['number' => 'descending']" :current="$sort">Quotation</x-admin.table.heading>
            <x-admin.table.heading align="right">Value</x-admin.table.heading>
        </x-slot:head>
        @foreach ($quotes as $quote)
            <x-admin.table.row wire:key="quote-{{ $quote->id }}"> <x-admin.table.cell>…</x-admin.table.cell> </x-admin.table.row>
        @endforeach
        <x-slot:empty> <x-admin.empty title="No quotations yet" /> </x-slot:empty>
    </x-admin.table>

    Its five states, in the owner's words: loading, empty, error, no-permission, success. A screen that gives
    :rows gets "empty" and "success" by itself; "loading", "error" and "no-permission" a screen says with state="…".
    While Livewire is asking, the rows dim and a screen reader is told; that is not a state of its own.

    The pages are the shared page links (components.admin.pagination). The words and roles are held by
    tests/Unit/AdminTableTest.php; which screens still write a table by hand is counted there too.
--}}
@php
    $paged = $rows instanceof \Illuminate\Contracts\Pagination\Paginator;
    $count = match (true) {
        $paged => $rows->count(),
        is_countable($rows) => count($rows),
        default => null,
    };
    $state ??= $count === 0 ? 'empty' : 'success';

    if (! in_array($state, ['loading', 'empty', 'error', 'no-permission', 'success'], true)) {
        throw new \InvalidArgumentException("A table has five states: loading, empty, error, no-permission, success. [{$state}] is none of them.");
    }

    $columns ??= max(1, substr_count((string) ($head ?? ''), '<th'));
    $chosen = is_array($selected) ? count($selected) : 0;
    $name = \Illuminate\Support\Str::lcfirst((string) $label);
    $rule = 'px-6 py-12 text-center';
@endphp
<div {{ $attributes->class(['wx-table']) }} data-table-state="{{ $state }}">
    @isset($filters)
        <div role="search" aria-label="Filter {{ $name }}" {{ $filters->attributes->class(['mb-4']) }}>{{ $filters }}</div>
    @endisset

    @if ($chosen > 0)
        <div role="group" aria-label="Actions on the chosen rows"
             class="sticky top-[calc(var(--wx-topbar-h)+8px)] z-20 mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-navy-200 bg-navy-50 px-4 py-2.5 text-theme-sm shadow-theme-xs dark:border-white/10 dark:bg-gray-900">
            <span class="font-medium text-navy-900 dark:text-white" role="status">{{ $chosen }} selected</span>
            {{ $bulk ?? '' }}
            <button type="button" x-data x-on:click="$wire.set($el.dataset.model, [])" data-model="{{ $model }}"
                    class="ml-auto text-theme-xs text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">Clear selection</button>
        </div>
    @endif

    <{{ $card ? 'section' : 'div' }} @class(['wx-surface wx-card rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]' => $card])>
        <div class="overflow-x-auto" wire:loading.class="opacity-60" @if ($state === 'loading') aria-busy="true" @endif>
            <table @class(['min-w-full text-left text-theme-sm', $tableClass])>
                <caption class="sr-only">{{ $label }}</caption>
                @isset($head)
                    <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                        <tr>{{ $head }}</tr>
                    </thead>
                @endisset
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @if ($state === 'success')
                        {{ $slot }}
                    @elseif ($state === 'loading')
                        @for ($line = 0; $line < 3; $line++)
                            <tr aria-hidden="true">
                                <td colspan="{{ $columns }}" class="px-5 py-3"><span class="block h-4 rounded bg-gray-100 dark:bg-white/5"></span></td>
                            </tr>
                        @endfor
                        <tr>
                            <td colspan="{{ $columns }}" class="px-5 py-3 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                <span role="status">Loading {{ $name }}…</span>
                            </td>
                        </tr>
                    @elseif ($state === 'empty')
                        <tr>
                            <td colspan="{{ $columns }}">
                                @isset($empty)
                                    <div role="status">{{ $empty }}</div>
                                @else
                                    <x-admin.empty role="status" :icon="$emptyIcon" :title="$emptyTitle ?? 'Nothing here yet'" :description="$emptyDescription" />
                                @endisset
                            </td>
                        </tr>
                    @elseif ($state === 'error')
                        <tr>
                            <td colspan="{{ $columns }}">
                                <div role="alert" class="flex flex-col items-center justify-center {{ $rule }}">
                                    <span class="mb-3 flex size-12 items-center justify-center rounded-xl bg-error-50 text-error-600 dark:bg-error-500/15">
                                        <x-icon name="alert" class="size-6" />
                                    </span>
                                    <h3 class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">{{ $errorTitle ?? $label.' could not be loaded' }}</h3>
                                    @if ($errorDescription)
                                        <p class="mt-1 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">{{ $errorDescription }}</p>
                                    @endif
                                    @isset($error)
                                        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $error }}</div>
                                    @elseif ($retry)
                                        <div class="mt-5"><x-admin.button variant="secondary" icon="refresh" wire:click="$refresh">Try again</x-admin.button></div>
                                    @endisset
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td colspan="{{ $columns }}">
                                <div role="status" class="flex flex-col items-center justify-center {{ $rule }}">
                                    <span class="mb-3 flex size-12 items-center justify-center rounded-xl bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                        <x-icon name="lock" class="size-6" />
                                    </span>
                                    <h3 class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">{{ $forbiddenTitle ?? 'You do not have access to this list' }}</h3>
                                    @if ($forbiddenDescription)
                                        <p class="mt-1 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">{{ $forbiddenDescription }}</p>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                </tbody>
                @if ($state === 'success' && isset($foot))
                    <tfoot>{{ $foot }}</tfoot>
                @endif
            </table>
        </div>
        {{-- Said to a screen reader while Livewire is asking for the rows again: the words change, so they are read. --}}
        <p class="sr-only" role="status"><span wire:loading>Loading {{ $name }}…</span></p>
        @if ($state === 'success' && $paged)
            {{ $rows instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $rows->links('components.admin.pagination') : $rows->links() }}
        @endif
    </{{ $card ? 'section' : 'div' }}>
</div>

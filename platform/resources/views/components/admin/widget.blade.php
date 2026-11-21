@use('App\Domain\System\Dashboard\WidgetState')
@props([
    'widget',                       // App\Domain\System\Dashboard\DrawnWidget: its name, its state and what it found
    'flush' => false,               // true where the content reaches the edges (a list of rows)
])
{{--
    The frame of one widget of the dashboard (master list T-044; the owner's document 07 §1: "Widget loading,
    empty, error and no-access states"; the decision is DL-044).

    <x-admin.widget :widget="$widget">
        …what the widget shows when it is ready…
        <x-slot:definition>1 Nov – 20 Nov 2026 · PKR · what is counted, in one sentence</x-slot:definition>
    </x-admin.widget>

    Its states, in the owner's words. Which one is the Board's decision (App\Domain\System\Dashboard\Board):
      ready      the content above;
      empty      nothing to count: the widget's own sentence, not a row of zeros, and nothing else. A widget
                 that counts over dates names them in that sentence (Widget::emptyWords);
      error      it could not be worked out: said here with the request number and "Try again". The other
                 widgets are drawn;
      no-access  the right to it was taken away while the page stood open: said here. (A widget the person's
                 role may not see is not on the page at all.)
      loading    not a state the server sends: the layer at the foot of this frame, shown while Livewire asks
                 again for the fields named in wire:target. A widget that follows the period waits for the
                 period and the business line; every widget waits for "Try again". The minute's own refresh
                 names no field, so it shows no layer.

    data-widget and data-widget-state are what the tests and the browser journey read. A figure inside is an
    <x-admin.figure>, which carries the count and the door to its rows.
--}}
@php
    $state = $widget->state;
    $id = 'widget-'.str_replace('_', '-', $widget->key);
    $waitsFor = $widget->followsPeriod ? 'preset, line, from, to, refresh' : 'refresh';
    $name = \Illuminate\Support\Str::lcfirst($widget->title);
@endphp
<section {{ $attributes->class(['wx-widget wx-surface wx-card relative flex min-w-0 flex-col rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}
         data-widget="{{ $widget->key }}" data-widget-state="{{ $state->value }}" aria-labelledby="{{ $id }}-title"
         wire:key="{{ $id }}" wire:loading.attr="aria-busy" wire:target="{{ $waitsFor }}">
    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-gray-200 px-5 py-3.5 dark:border-gray-800">
        <div class="min-w-0">
            <h2 id="{{ $id }}-title" class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">{{ $widget->title }}</h2>
            @if ($widget->description !== '')
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $widget->description }}</p>
            @endif
        </div>
        {{-- A widget's own links stand beside its heading only while it has something to show. --}}
        @if (isset($actions) && $state === WidgetState::Ready)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endif
    </header>

    <div @class(['flex-1', 'p-5' => ! $flush || $state !== WidgetState::Ready]) wire:loading.class="opacity-40" wire:target="{{ $waitsFor }}">
        @if ($state === WidgetState::Ready)
            {{ $slot }}
        @elseif ($state === WidgetState::Empty)
            <div class="flex h-full flex-col items-center justify-center px-2 py-4 text-center" role="status" data-widget-empty>
                <span class="wx-stat-tile mb-3 flex size-11 items-center justify-center rounded-xl"><x-icon name="check-circle" class="size-5" /></span>
                @isset($empty)
                    {{ $empty }}
                @else
                    <p class="max-w-md text-theme-sm text-gray-600 dark:text-gray-300">{{ $widget->emptyWords }}</p>
                @endisset
            </div>
        @elseif ($state === WidgetState::Error)
            <div class="flex flex-col items-center justify-center px-2 py-6 text-center" role="alert" data-widget-error>
                <span class="mb-3 flex size-11 items-center justify-center rounded-xl bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-500"><x-icon name="alert" class="size-5" /></span>
                <p class="text-theme-sm font-semibold text-gray-900 dark:text-white/90">{{ $widget->title }} could not be loaded</p>
                <p class="mt-1 max-w-md text-theme-sm text-gray-600 dark:text-gray-300">Nothing was changed, and the rest of this page is not affected. Try again in a moment.</p>
                @if ($widget->reference !== null)
                    <p class="mt-2 text-theme-xs text-gray-500">Request number <strong class="font-semibold whitespace-nowrap select-all" data-request-number>{{ $widget->reference }}</strong></p>
                @endif
                <x-admin.button size="sm" variant="secondary" class="mt-4" wire:click="refresh">Try again</x-admin.button>
            </div>
        @else
            <div class="flex flex-col items-center justify-center px-2 py-6 text-center" role="status" data-widget-no-access>
                <span class="mb-3 flex size-11 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300"><x-icon name="lock" class="size-5" /></span>
                @isset($forbidden)
                    {{ $forbidden }}
                @else
                    <p class="text-theme-sm font-semibold text-gray-900 dark:text-white/90">You no longer have access to {{ $name }}</p>
                    <p class="mt-1 max-w-md text-theme-sm text-gray-600 dark:text-gray-300">Your role was changed while this page was open. If you need it for your work, ask the owner to change your role.</p>
                @endisset
            </div>
        @endif
    </div>

    {{-- What a chart is about: its dates, its currency, what it counts. Only where there is a chart. --}}
    @if (isset($definition) && $state === WidgetState::Ready)
        <footer class="border-t border-gray-100 px-5 py-2.5 text-theme-xs leading-5 text-gray-500 dark:border-gray-800 dark:text-gray-400" data-widget-definition>{{ $definition }}</footer>
    @endif

    {{-- Loading. Hidden by its class until Livewire shows it; .delay keeps a quick answer from flashing it. --}}
    <div class="absolute inset-0 hidden items-center justify-center rounded-2xl" role="status" data-widget-loading
         wire:loading.flex.delay wire:target="{{ $waitsFor }}">
        <span class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-theme-xs font-medium text-gray-700 shadow-theme-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">Loading {{ $name }}…</span>
    </div>
</section>

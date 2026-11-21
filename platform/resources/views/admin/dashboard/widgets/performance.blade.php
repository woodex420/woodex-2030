{{-- Business performance: the period's four figures. The money is the revenue report's own totals. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($figures as $card)
            @php($figure = $card['figure'])
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
                <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $figure->label }}</p>
                <p class="mt-1.5 text-2xl leading-8 font-bold tracking-tight tabular-nums text-gray-900 dark:text-white">{{ $figure->value($lakh) }}</p>
                <p class="mt-1 text-theme-xs text-gray-500">{{ $figure->hint }}</p>
                <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-theme-xs">
                    <x-admin.figure :figure="$figure" class="font-semibold">{{ $figure->href === null ? $figure->counted() : 'Open the '.$figure->counted().' →' }}</x-admin.figure>
                    @if ($card['report'])
                        <a href="{{ $card['report'] }}" wire:navigate class="font-semibold text-brand-600 dark:text-brand-400">Open matching report →</a>
                    @endif
                </p>
            </div>
        @endforeach
    </div>

    <x-slot:definition>
        {{ $dates }} · {{ config('app.timezone') }} · {{ $line ?? 'all business lines' }}{{ $money ? ' · amounts in PKR' : '' }}.
        {{ implode('. ', $means) }}.
    </x-slot:definition>
</x-admin.widget>

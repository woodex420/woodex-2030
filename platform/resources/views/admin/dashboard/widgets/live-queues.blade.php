{{-- The six control numbers: one query each, from ControlPanel, and each tile is the door to the same rows. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($tiles as $tile)
            <x-admin.figure :figure="$tile" as="block"
                            class="wx-stat group flex items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-5 transition dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="min-w-0">
                    <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $tile->label }}</p>
                    <p class="mt-1.5 truncate text-2xl leading-8 font-bold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $tile->value() }}</p>
                    @if ($tile->hint !== '')
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $tile->hint }}</p>
                    @endif
                </div>
                <span @class([
                    'flex size-11 shrink-0 items-center justify-center rounded-xl',
                    'wx-stat-tile' => ! in_array($tile->tone, ['wood', 'success', 'warning', 'error'], true),
                    'bg-wood-50 text-wood-700 dark:bg-wood-500/10 dark:text-wood-300' => $tile->tone === 'wood',
                    'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500' => $tile->tone === 'success',
                    'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500' => $tile->tone === 'warning',
                    'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-500' => $tile->tone === 'error',
                ])>
                    <x-icon :name="$tile->icon" class="size-5" />
                </span>
            </x-admin.figure>
        @endforeach
    </div>
</x-admin.widget>

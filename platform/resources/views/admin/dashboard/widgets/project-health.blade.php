{{-- Project health: every active project in exactly one of four states, each a door to those projects. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <ul class="space-y-3.5" aria-label="Active projects by health">
        @foreach ($states as $state)
            <li>
                <div class="mb-1 flex items-baseline justify-between gap-3 text-theme-sm">
                    <span class="text-gray-600 dark:text-gray-300">{{ $state->label }}</span>
                    <x-admin.figure :figure="$state" class="tabular-nums" aria-label="{{ $state->label }}: open the {{ $state->counted() }}">{{ number_format($state->count) }}</x-admin.figure>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5" aria-hidden="true">
                    <div @class([
                        'h-full rounded-full',
                        'bg-error-500' => $state->tone === 'error',
                        'bg-warning-500' => $state->tone === 'warning',
                        'bg-success-500' => $state->tone === 'success',
                        'bg-navy-600' => $state->tone === 'navy',
                    ]) style="width: {{ round($state->count / $most * 100) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>

    <p class="mt-5 flex items-baseline justify-between gap-3 border-t border-gray-100 pt-4 text-theme-sm dark:border-gray-800">
        <span class="text-gray-600 dark:text-gray-300">{{ $active->label }}</span>
        <x-admin.figure :figure="$active" class="tabular-nums" aria-label="{{ $active->label }}: open the {{ $active->counted() }}">{{ number_format($active->count) }}</x-admin.figure>
    </p>

    <x-slot:definition>
        As it stands on {{ $as_of }} · a count of projects, no money.
        Past the target date comes first; then a milestone that is not reached and past its own date; then on schedule; a project without a target date cannot be judged.
        Each active project is counted once.
    </x-slot:definition>
</x-admin.widget>

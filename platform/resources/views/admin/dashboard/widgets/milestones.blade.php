{{-- Coming milestones: how many active projects have one soon (the door to them), and the next few by date. --}}
<x-admin.widget :widget="$widget" :class="$span" flush>
    <x-slot:actions>
        <x-admin.figure :figure="$projects" class="text-theme-sm">Open the {{ $projects->counted() }} →</x-admin.figure>
    </x-slot:actions>

    <ul role="list">
        @foreach ($next as $milestone)
            <li wire:key="milestone-{{ $milestone['id'] }}" class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-gray-800 dark:text-white/90">{{ $milestone['name'] }}</p>
                    <p class="truncate text-theme-xs text-gray-500">
                        <a href="{{ $milestone['href'] }}" wire:navigate class="hover:underline">{{ $milestone['project'] }}</a>
                        <span class="text-gray-400">· {{ $milestone['code'] }}</span>
                    </p>
                </div>
                <span class="text-theme-xs tabular-nums text-gray-500">{{ $milestone['due'] }}</span>
                <x-admin.badge :color="$milestone['soon'] ? 'warning' : 'gray'">{{ $milestone['in'] }}</x-admin.badge>
            </li>
        @endforeach
    </ul>

    <x-slot:definition>
        {{ $dates }} · a count of projects, no money.
        Milestones of active projects that are not reached and fall due in these days, the nearest first; the first {{ $named }} are named.
        A milestone that is already past its date is under "Project health".
    </x-slot:definition>
</x-admin.widget>

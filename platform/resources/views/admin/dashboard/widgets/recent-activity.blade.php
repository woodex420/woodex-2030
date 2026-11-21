{{-- The day's record: what happened last, with who and when. Nothing is counted here. --}}
<x-admin.widget :widget="$widget" :class="$span" flush>
    <x-slot:empty>
        <p class="text-[15px] leading-6 font-semibold text-gray-900 dark:text-white/90">No activity yet</p>
        <p class="mt-1 max-w-md text-theme-sm text-gray-500 dark:text-gray-400">New enquiries from the website, WhatsApp and the team will appear here.</p>
    </x-slot:empty>

    @foreach ($activities as $activity)
        <div class="flex gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
            <span class="mt-1 size-2 shrink-0 rounded-full bg-wood-500" aria-hidden="true"></span>
            <div class="min-w-0">
                <p class="text-theme-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium text-gray-900 dark:text-white">{{ $activity['who'] }}</span>
                    · {{ $activity['what'] }}
                </p>
                <p class="text-theme-xs text-gray-400">{{ $activity['when'] }}{{ $activity['by'] ? ' · '.$activity['by'] : '' }}</p>
            </div>
        </div>
    @endforeach
</x-admin.widget>

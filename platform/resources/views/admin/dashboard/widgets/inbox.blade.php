{{-- Conversations (the optional Inbox module): what is unread and what is open, each a door to the inbox. --}}
<x-admin.widget :widget="$widget" :class="$span">
    <dl class="grid grid-cols-2 gap-3">
        @foreach ([$unread, $open] as $figure)
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
                <dt class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $figure->label }}</dt>
                <dd class="mt-1.5 text-2xl leading-8 font-bold tracking-tight tabular-nums text-gray-900 dark:text-white">{{ number_format($figure->count) }}</dd>
                <dd class="mt-2 text-theme-xs"><x-admin.figure :figure="$figure" class="font-semibold">Open the {{ $figure->counted() }} →</x-admin.figure></dd>
            </div>
        @endforeach
    </dl>

    <x-slot:definition>
        As it stands now · a count of conversations, no money.
        Unread: any conversation with a message nobody has opened, whatever its status. Open: conversations whose status is open.
    </x-slot:definition>
</x-admin.widget>

<div class="wx-workspace">
    <x-admin.page-header title="Notification centre" description="Your Woodex updates, reminders and mentions. Read, review or archive without losing the history." :breadcrumbs="['Notifications' => null]">
        <x-slot:actions><x-admin.button variant="secondary" icon="check" wire:click="markAllRead" :disabled="$unread === 0">Mark all read</x-admin.button></x-slot:actions>
    </x-admin.page-header>
    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-5 dark:border-gray-800">
            <div class="flex gap-1" role="group" aria-label="Notification filters">
                @foreach (['all' => 'All updates', 'unread' => 'Unread', 'archived' => 'Archived'] as $value => $label)
                    <button type="button" wire:click="$set('filter', '{{ $value }}')" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['wx-underline-tab', 'is-active' => $filter === $value])>{{ $label }} @if ($value === 'unread')<span class="wx-count">{{ $unread }}</span>@endif</button>
                @endforeach
            </div>
            <div class="relative my-3 w-full sm:w-72"><x-icon name="search" class="absolute top-3 left-3 size-4 text-gray-400" /><input type="search" wire:model.live.debounce.300ms="search" class="wx-input pl-10" placeholder="Search your updates…" aria-label="Search notifications"></div>
        </header>
        <div class="mx-auto max-w-4xl">
            @forelse ($notifications as $notification)
                <x-admin.notification-row :notification="$notification" />
            @empty
                <x-admin.empty icon="bell" title="No notifications" description="No updates match this view. Try another filter or search." />
            @endforelse
        </div>
        {{ $notifications->links('components.admin.pagination') }}
    </section>
</div>

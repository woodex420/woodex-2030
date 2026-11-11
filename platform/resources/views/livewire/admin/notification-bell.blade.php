<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" wire:poll.30s.visible>
    <button type="button" x-ref="trigger" x-on:click="open = ! open" :aria-expanded="open" aria-controls="notification-panel"
            class="relative flex size-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-100 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5"
            aria-haspopup="dialog" aria-label="Notifications{{ $unread > 0 ? " ({$unread} unread)" : '' }}">
        <x-icon name="bell" />
        @if ($unread > 0)<span class="absolute -top-0.5 -right-0.5 flex size-5 items-center justify-center rounded-full bg-wood-500 text-[10px] font-semibold text-white">{{ min($unread, 9) }}{{ $unread > 9 ? '+' : '' }}</span>@endif
    </button>
    <section id="notification-panel" x-cloak x-show="open" x-transition.opacity role="dialog" aria-label="Notification inbox" x-trap="open"
             class="wx-alert-panel absolute right-0 z-50 mt-3 w-[26rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
        <header class="flex items-center justify-between p-4">
            <div><h2 class="font-semibold text-gray-900 dark:text-white">Notifications</h2><p class="mt-0.5 text-theme-xs text-gray-500">{{ $unread }} unread updates</p></div>
            <button type="button" x-on:click="open = false; $refs.trigger.focus()" aria-label="Close notifications" class="wx-icon-button"><x-icon name="x" class="size-4" /></button>
        </header>
        <div class="flex items-center gap-1 border-b border-gray-100 px-4 dark:border-gray-800" role="group" aria-label="Notification filters">
            @foreach (['all' => 'All', 'unread' => 'Unread', 'archived' => 'Archived'] as $value => $label)
                <button type="button" wire:click="$set('filter', '{{ $value }}')" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}" @class(['wx-underline-tab', 'is-active' => $filter === $value])>{{ $label }}</button>
            @endforeach
        </div>
        <div class="max-h-[55dvh] overflow-y-auto overscroll-contain">
            @forelse ($notifications as $notification)
                <x-admin.notification-row :notification="$notification" />
            @empty
                <div class="px-5 py-12 text-center"><x-icon name="bell" class="mx-auto mb-3 size-6 text-gray-300" /><p class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">You're all caught up.</p><p class="mt-1 text-theme-xs text-gray-400">{{ $filter === 'archived' ? 'Archived updates will appear here.' : 'New Woodex updates appear here.' }}</p></div>
            @endforelse
        </div>
        <footer class="flex items-center justify-between gap-3 border-t border-gray-100 p-3 text-theme-xs dark:border-gray-800">
            <button type="button" wire:click="markAllRead" wire:loading.attr="disabled" @disabled($unread === 0) class="font-medium text-gray-500 disabled:opacity-40">Mark all read</button>
            <a href="{{ route('admin.notifications') }}" wire:navigate class="font-semibold text-brand-600 dark:text-gray-200">Notification centre →</a>
        </footer>
    </section>
</div>

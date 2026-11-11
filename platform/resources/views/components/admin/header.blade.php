@props(['title' => null])
@php
    $user = auth()->user();
@endphp
{{-- No backdrop-blur on the header: a filtered ancestor becomes the containing block for position: fixed,
     which trapped the website-chat drawer inside this 72px bar. A solid header looks the same. --}}
<header class="wx-admin-header sticky top-0 z-30 flex min-h-[72px] flex-wrap items-center py-2 sm:h-[72px] sm:flex-nowrap sm:py-0 gap-3 border-b border-gray-200 bg-white px-4 md:px-6 dark:border-gray-800 dark:bg-gray-900">
    <button type="button" @click="$store.sidebar.toggle()"
            class="flex size-10 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5"
            aria-label="Toggle sidebar">
        <x-icon name="menu" />
    </button>

    {{-- Global search (GLB-002): Ctrl/⌘ K command palette --}}
    <livewire:admin.global-search />

    <div class="ml-auto flex items-center gap-2">
        <label class="wx-theme-picker flex items-center gap-1 rounded-full border px-2" title="Woodex Light / Night — follow your device with System">
            <x-icon name="moon" class="size-4 shrink-0" />
            <select :value="$store.theme.mode" @change="$store.theme.setMode($event.target.value)" aria-label="Color theme" class="h-9 max-w-24 cursor-pointer border-0 bg-transparent text-theme-xs outline-none">
                <option value="system">System</option><option value="light">Light</option><option value="dark">Dark</option>
            </select>
        </label>

        {{-- Notifications (GLB-008) --}}
        <livewire:admin.conversations.live-chat-bell />
        <livewire:admin.notification-bell />

        {{-- Assistant drawer (slice M): fixed questions over the records, on every page --}}
        <livewire:admin.assistant-drawer />

        {{-- User menu --}}
        <div class="hs-dropdown relative inline-flex [--placement:bottom-right]">
            <button type="button" class="hs-dropdown-toggle flex items-center gap-2 rounded-full py-1 pr-2 pl-1 hover:bg-gray-100 dark:hover:bg-white/5" aria-haspopup="menu" aria-expanded="false">
                <span class="flex size-9 items-center justify-center rounded-full bg-navy-900 text-theme-xs font-semibold text-white">{{ $user?->initials() }}</span>
                <span class="hidden text-left sm:block">
                    <span class="block text-theme-sm leading-tight font-medium text-gray-800 dark:text-white/90">{{ $user?->name }}</span>
                    <span class="block text-theme-xs leading-tight text-gray-500">{{ \Illuminate\Support\Str::headline($user?->getRoleNames()->first() ?? 'staff') }}</span>
                </span>
                <x-icon name="chevron-down" class="size-4 text-gray-400" />
            </button>
            <div class="hs-dropdown-menu z-50 mt-2 hidden w-60 rounded-xl border border-gray-200 bg-white p-2 opacity-0 shadow-theme-lg transition-[opacity,margin] hs-dropdown-open:opacity-100 dark:border-gray-800 dark:bg-gray-900" role="menu">
                <div class="border-b border-gray-100 px-3 pt-1 pb-3 dark:border-gray-800">
                    <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $user?->name }}</p>
                    <p class="truncate text-theme-xs text-gray-500">{{ $user?->email }}</p>
                </div>
                @if (! app()->environment('production'))
                    <div class="wx-environment m-1 rounded-lg border px-3 py-2 text-theme-xs" role="note">
                        <strong>{{ app()->isLocal() ? 'Local preview' : 'Non-production environment' }}</strong>
                        <p class="mt-1">Sample records may exist. This label describes the environment, not record ownership.</p>
                    </div>
                @endif
                <a href="{{ route('admin.profile.security') }}" wire:navigate class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2 text-theme-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
                    <x-icon name="shield" class="size-4" /> Security &amp; Telegram
                </a>
                @if (request()->attributes->get('synthetic_direct_preview') === true)
                    <a href="{{ route('login', [], false) }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-theme-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">Leave admin demo</a>
                @else
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-theme-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
                        <x-icon name="logout" class="size-4" /> Sign out
                    </button>
                </form>
                @endif
            </div>
        </div>
    </div>
</header>

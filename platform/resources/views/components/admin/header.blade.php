@props(['title' => null])
@php
    $user = auth()->user();
    // Where this screen is in the menu (the one registry), and what this person can start from anywhere.
    $location = \App\Support\AdminNavigation::location($user, request());
    $quickCreate = \App\Support\AdminNavigation::quickCreate($user);
    // A page that has its own trail (x-admin.page-header :breadcrumbs) sends it here.
    $trail = trim($__env->yieldPushContent('wx-breadcrumbs'));
@endphp
{{--
    The top bar (docs/woodex-os/00-MASTER-PLAN.md §7.2, slice 1.4): where you are, search, "New", notifications,
    the assistant and the user menu — in ONE row at every width. Until 2026-11-17 the theme picker stood here and
    pushed the bar into two rows (113 px) on a phone; it is in the user menu now.

    No backdrop-blur on the header: a filtered ancestor becomes the containing block for position: fixed,
    which trapped the website-chat drawer inside this bar. A solid header looks the same.
--}}
<header class="wx-admin-header sticky top-0 z-30 flex h-(--wx-topbar-h) items-center gap-1.5 border-b px-3 sm:gap-3 sm:px-4 md:px-6">
    <button type="button" @click="$store.sidebar.toggle()" class="wx-topbar-button" aria-label="Open or close the menu" aria-controls="wx-sidebar">
        <x-icon name="menu" />
    </button>

    {{-- Where you are. Hidden on a phone: the page's own heading says it, and x-admin.page-header gives the way back. --}}
    @if ($trail !== '' || $location !== null)
        <nav class="wx-topbar-trail hidden min-w-0 shrink items-center gap-1.5 text-theme-sm lg:flex" aria-label="Breadcrumb">
            @if ($trail !== '')
                {!! $trail !!}
            @else
                <span class="wx-topbar-crumb">{{ $location['group'] }}</span>
                <x-icon name="chevron-right" class="size-3.5 shrink-0" />
                @if ($location['here'])
                    <span class="wx-topbar-crumb is-current" aria-current="page">{{ $location['label'] }}</span>
                @else
                    <a href="{{ $location['url'] }}" wire:navigate class="wx-topbar-crumb">{{ $location['label'] }}</a>
                @endif
            @endif
        </nav>
    @endif

    {{-- Global search (GLB-002): Ctrl/⌘ K command palette --}}
    <livewire:admin.global-search />

    <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
        {{-- Quick create: only what this person may start, only screens that open (AdminNavigation::quickCreate). --}}
        @if ($quickCreate !== [])
            <div class="hs-dropdown relative inline-flex [--placement:bottom-right]">
                <button type="button" class="hs-dropdown-toggle wx-topbar-new" aria-haspopup="menu" aria-expanded="false" aria-label="Create new">
                    <x-icon name="plus" class="size-4.5" />
                    <span class="hidden md:inline">New</span>
                </button>
                <div class="hs-dropdown-menu wx-topbar-menu z-50 mt-2 hidden w-60 opacity-0 transition-[opacity,margin] hs-dropdown-open:opacity-100" role="menu" aria-label="Create new">
                    @foreach ($quickCreate as $entry)
                        <a href="{{ $entry['url'] }}" wire:navigate role="menuitem" data-quick-create="{{ $entry['id'] }}" class="wx-topbar-menu-item">
                            <x-icon :name="$entry['icon']" class="size-4 shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{ $entry['label'] }}</span>
                            @if ($entry['shortcut'])
                                <kbd class="wx-kbd hidden sm:inline" title="Keyboard shortcut">{{ $entry['shortcut'] }}</kbd>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Notifications (GLB-008) --}}
        @if (\App\Support\Modules::enabled('inbox'))
            <livewire:admin.conversations.live-chat-bell />
        @endif
        <livewire:admin.notification-bell />

        {{-- Assistant drawer (slice M): fixed questions over the records, on every page --}}
        <livewire:admin.assistant-drawer />

        {{-- User menu: who is signed in, the colour theme, security, sign out. It stays open while a theme is
             chosen (--auto-close:outside), so the change is seen and another can be picked. --}}
        <div class="hs-dropdown relative inline-flex [--auto-close:outside] [--placement:bottom-right]">
            <button type="button" class="hs-dropdown-toggle wx-topbar-user" aria-haspopup="menu" aria-expanded="false" aria-label="Your account: {{ $user?->name }}">
                <span class="wx-topbar-avatar">{{ $user?->initials() }}</span>
                <span class="hidden text-left xl:block">
                    <span class="block max-w-40 truncate text-theme-sm leading-tight font-medium">{{ $user?->name }}</span>
                    <span class="wx-topbar-role block text-theme-xs leading-tight">{{ \Illuminate\Support\Str::headline($user?->getRoleNames()->first() ?? 'staff') }}</span>
                </span>
                <x-icon name="chevron-down" class="hidden size-4 sm:block" />
            </button>
            <div class="hs-dropdown-menu wx-topbar-menu z-50 mt-2 hidden w-64 opacity-0 transition-[opacity,margin] hs-dropdown-open:opacity-100" role="menu">
                <div class="wx-topbar-menu-head">
                    <p class="truncate text-theme-sm font-medium">{{ $user?->name }}</p>
                    <p class="wx-topbar-role truncate text-theme-xs">{{ $user?->email }}</p>
                </div>
                @if (! app()->environment('production'))
                    {{-- A demo says that it is one (the hosted demo runs as "local" so that it can never count as
                         production); any other non-production system keeps the plain label. --}}
                    @if (\App\Support\SyntheticPreview::enabled())
                        <div class="wx-environment m-1 rounded border px-3 py-2 text-theme-xs" role="note" data-environment="demo">
                            <strong>Demo workspace</strong>
                            <p class="mt-1">Sample data only. It can be erased at any time.</p>
                        </div>
                    @else
                        <div class="wx-environment m-1 rounded border px-3 py-2 text-theme-xs" role="note" data-environment="{{ app()->isLocal() ? 'local' : 'non-production' }}">
                            <strong>{{ app()->isLocal() ? 'Local preview' : 'Non-production environment' }}</strong>
                            <p class="mt-1">Sample records may exist. This label describes the environment, not record ownership.</p>
                        </div>
                    @endif
                @endif

                {{-- Colour theme: Woodex Light / Night, or follow the device. --}}
                <div class="px-3 pt-2 pb-1" role="group" aria-label="Color theme">
                    <p class="wx-topbar-role mb-1.5 text-theme-xs">Theme</p>
                    <div class="wx-theme-picker grid grid-cols-3 gap-1 rounded border p-1">
                        @foreach (['system' => ['System', 'settings'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $mode => [$label, $icon])
                            <button type="button" @click="$store.theme.setMode('{{ $mode }}')" :aria-pressed="($store.theme.mode === '{{ $mode }}').toString()" data-theme-choice="{{ $mode }}"
                                    class="wx-theme-choice">
                                <x-icon :name="$icon" class="size-3.5" /> {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('admin.profile.security') }}" wire:navigate role="menuitem" class="wx-topbar-menu-item mt-1">
                    <x-icon name="shield" class="size-4 shrink-0" /> Security &amp; Telegram
                </a>
                @if (request()->attributes->get('synthetic_direct_preview') === true)
                    <a href="{{ route('login', [], false) }}" role="menuitem" class="wx-topbar-menu-item">Leave admin demo</a>
                @else
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" role="menuitem" class="wx-topbar-menu-item w-full">
                            <x-icon name="logout" class="size-4 shrink-0" /> Sign out
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</header>

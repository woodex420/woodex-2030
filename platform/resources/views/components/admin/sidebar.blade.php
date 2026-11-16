@php
    $groups = \App\Support\AdminNavigation::for(auth()->user());
    $navUser = auth()->user();

    // P10.1: live badges — unread conversations and quotations waiting for approval. Passive counts
    // only (A0): nothing is sent anywhere from here.
    $badges = [];
    if ($navUser?->can('inbox.view')) {
        $badges['admin.inbox'] = \App\Domain\Conversations\Models\Conversation::query()->where('unread_count', '>', 0)->count();
    }
    if ($navUser?->can('quotes.approve')) {
        $badges['admin.approvals'] = \App\Domain\System\Models\Approval::query()
            ->where('approvable_type', 'quote')
            ->where('status', \App\Domain\System\Enums\ApprovalStatus::Pending)
            ->count();
    }
@endphp
{{--
    The sidebar: only destinations that exist here and that this person may open
    (AdminNavigation::for). Everything else is on the Feature status screen, under System.
    Colours come from the --wx-sidebar-* tokens in resources/css/admin.css.
--}}
<aside x-data="{ navQuery: '' }" x-effect="if (!$store.sidebar.expanded && !$store.sidebar.mobileOpen) navQuery = ''" x-on:keydown.escape.window="$store.sidebar.close()"
    {{-- As a drawer on a phone the menu holds the focus until it is closed (Alpine's focus plugin, which
         Livewire ships); on a desktop it is part of the page and holds nothing. --}}
    x-trap.noscroll="$store.sidebar.mobileOpen"
    class="wx-admin-sidebar fixed inset-y-0 left-0 z-50 flex h-screen w-[280px] -translate-x-full flex-col border-r px-4 transition-all duration-300 ease-in-out xl:w-(--wx-sidebar-w) xl:translate-x-0"
    :class="$store.sidebar.mobileOpen && 'translate-x-0!'"
    aria-label="Main navigation">
    <div class="wx-sidebar-brand flex h-[72px] shrink-0 items-center px-2">
        <a href="{{ route('admin.dashboard') }}" wire:navigate class="flex items-center rounded-lg">
            <x-brand-logo subtitle="OPERATING SYSTEM" x-show="$store.sidebar.expanded || $store.sidebar.mobileOpen" />
            <x-brand-logo compact x-cloak x-show="!$store.sidebar.expanded && !$store.sidebar.mobileOpen" />
        </a>
    </div>

    <div class="relative mb-1" x-show="$store.sidebar.expanded || $store.sidebar.mobileOpen">
        <x-icon name="search" class="wx-sidebar-search-icon pointer-events-none absolute top-3 left-3 size-4" />
        <input type="search" x-model="navQuery" @keydown.escape="if (navQuery) { navQuery = ''; $event.stopPropagation(); }"
               aria-label="Filter navigation" placeholder="Find a screen…" class="wx-sidebar-search h-10 w-full rounded-lg pr-9 pl-9 text-theme-sm">
        <button type="button" x-cloak x-show="navQuery" @click="navQuery = ''" aria-label="Clear navigation filter"
                class="wx-sidebar-search-icon absolute top-2 right-2 rounded p-1"><x-icon name="x" class="size-4" /></button>
    </div>
    <nav x-ref="navigation" class="no-scrollbar flex-1 overflow-y-auto pb-8" aria-label="Screens">
        <p x-cloak x-show="navQuery.trim() && !Array.from($refs.navigation.querySelectorAll('[data-nav-label]')).some(item => item.dataset.navLabel.toLowerCase().includes(navQuery.trim().toLowerCase()))"
           role="status" class="wx-sidebar-heading px-3 py-4 text-theme-sm">No matching screen in your menu. Feature status, under System, lists every part of WOODEX OS.</p>
        @foreach ($groups as $group)
            @php($groupKey = $group['id'])
            {{-- The group that holds the screen you are on is always open. --}}
            @php($holdsCurrent = collect($group['items'])->contains(fn (array $item): bool => request()->routeIs($item['route']) || request()->routeIs(\Illuminate\Support\Str::beforeLast($item['route'], '.index').'.*')))
            <div class="mt-5 first:mt-2" data-nav-group="{{ $groupKey }}"
                 x-show="!navQuery.trim() || Array.from($el.querySelectorAll('[data-nav-label]')).some(item => item.dataset.navLabel.toLowerCase().includes(navQuery.trim().toLowerCase()))">
                <button type="button"
                        x-show="$store.sidebar.expanded || $store.sidebar.mobileOpen"
                        @click="$store.sidebar.toggleGroup('{{ $groupKey }}')"
                        aria-controls="wx-nav-{{ $groupKey }}"
                        :aria-expanded="navQuery.trim() || {{ $holdsCurrent ? 'true' : 'false' }} || $store.sidebar.groupOpen('{{ $groupKey }}') ? 'true' : 'false'"
                        class="wx-sidebar-heading mb-2 flex w-full items-center justify-between gap-2 rounded px-3 py-1 text-[11px] font-semibold tracking-[0.12em] uppercase transition">
                    <span>{{ $group['title'] }}</span>
                    <span class="shrink-0 transition-transform duration-200"
                          :class="{{ $holdsCurrent ? 'true' : 'false' }} || $store.sidebar.groupOpen('{{ $groupKey }}') ? '' : '-rotate-90'">
                        <x-icon name="chevron-down" class="size-3.5" />
                    </span>
                </button>
                <ul id="wx-nav-{{ $groupKey }}" class="flex flex-col gap-0.5"
                    x-show="navQuery.trim() || {{ $holdsCurrent ? 'true' : 'false' }} || ! ($store.sidebar.expanded || $store.sidebar.mobileOpen) || $store.sidebar.groupOpen('{{ $groupKey }}')">
                    @foreach ($group['items'] as $item)
                        @php($badge = $badges[$item['route']] ?? 0)
                        @php($active = request()->routeIs($item['route']) || request()->routeIs(\Illuminate\Support\Str::beforeLast($item['route'], '.index').'.*'))
                        <li data-nav-label="{{ $item['label'] }}" x-show="!navQuery.trim() || $el.dataset.navLabel.toLowerCase().includes(navQuery.trim().toLowerCase())">
                            <a href="{{ route($item['route']) }}" data-nav-id="{{ $item['id'] }}"
                               @unless (in_array($item['route'], \App\Support\AdminNavigation::FULL_DOCUMENT, true)) wire:navigate @endunless
                               @class(['menu-item group relative', 'menu-item-active' => $active, 'menu-item-inactive' => ! $active])
                               :class="!$store.sidebar.expanded && !$store.sidebar.mobileOpen ? 'xl:justify-center' : ''"
                               @if ($active) aria-current="page" @endif
                               title="{{ $item['label'] }}">
                                <x-icon :name="$item['icon']" class="size-5 shrink-0" />
                                <span x-show="$store.sidebar.expanded || $store.sidebar.mobileOpen" class="truncate">{{ $item['label'] }}</span>
                                @if ($badge > 0)
                                    <span class="wx-sidebar-badge ml-auto rounded-full px-1.5 py-0.5 text-[10px] leading-none font-semibold"
                                          x-show="$store.sidebar.expanded || $store.sidebar.mobileOpen">{{ $badge > 99 ? '99+' : $badge }}</span>
                                    <span class="wx-sidebar-badge absolute top-1.5 right-2 size-2 rounded-full"
                                          x-cloak x-show="! ($store.sidebar.expanded || $store.sidebar.mobileOpen)"></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</aside>

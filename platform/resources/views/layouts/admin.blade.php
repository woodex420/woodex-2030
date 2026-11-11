<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f6f6f6">
    <title>{{ isset($title) ? $title.' · ' : '' }}Woodex OS</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script>
        (() => {
            let mode = 'system';
            try { mode = localStorage.getItem('wx.theme') || 'system'; } catch (e) {}
            if (!['light', 'dark', 'system'].includes(mode)) mode = 'system';
            const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.dataset.themeMode = mode;
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#171717' : '#f6f6f6');
            try { if (localStorage.getItem('wx.sidebar') === 'collapsed') document.documentElement.dataset.sidebar = 'collapsed'; } catch (e) {}
        })();
    </script>
    @if (request()->attributes->get('synthetic_direct_preview') === true)
        <meta name="woodex-demo-context" content="{{ request()->attributes->get('synthetic_demo_context') }}">
        <script type="module" src="{{ asset('__sandbox/direct-context.js') }}"></script>
    @endif
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @livewireStyles
</head>
<body class="wx-admin h-full">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[999999] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">Skip to content</a>

    {{-- GLB-016: keyboard shortcuts (N/Q//, G then D). Wires only the routes this user may use. --}}
    <div x-data="wxShortcuts" x-on:keydown.window="onKeydown($event)"
         data-new-lead="{{ auth()->user()?->can('create', \App\Domain\Crm\Models\Lead::class) ? route('admin.leads.index', ['tab' => 'list', 'new' => 1]) : '' }}"
         data-new-quote="{{ auth()->user()?->can('quotes.manage') ? route('admin.quotes.create') : '' }}"
         data-dashboard="{{ route('admin.dashboard') }}"
         class="min-h-screen xl:flex">
        {{-- Mobile backdrop --}}
        <div x-cloak x-show="$store.sidebar.mobileOpen" x-transition.opacity @click="$store.sidebar.close()"
             class="fixed inset-0 z-40 bg-gray-900/50 xl:hidden"></div>

        <x-admin.sidebar />

        <div class="flex min-w-0 flex-1 flex-col transition-[margin-left] duration-300 ease-in-out xl:ml-(--wx-sidebar-w)">
            <x-admin.header :title="$title ?? null" />

            <main id="main" class="mx-auto w-full max-w-(--breakpoint-2xl) flex-1 p-4 md:p-6">
                @if (request()->attributes->get('synthetic_direct_preview') === true)
                    <aside data-direct-preview-banner role="note" class="mb-5 rounded-xl border border-warning-500/40 bg-warning-50 px-4 py-3 text-theme-sm text-warning-700 dark:bg-warning-500/10">
                        <strong>Admin demo · no sign-in · NOT PRODUCTION</strong>
                        <p class="mt-1">Disposable synthetic data only. Do not enter real customer data or provider credentials. Production authentication is unchanged.</p>
                        <a href="{{ route('login', [], false) }}" class="mt-2 inline-block font-medium underline">View transparent welcome page</a>
                    </aside>
                @endif
                @if (session('warning'))
                    <div class="mb-5 flex items-start gap-3 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-warning-700 dark:bg-warning-500/10">
                        <x-icon name="alert" class="mt-0.5 size-5 shrink-0" />
                        <p>{{ session('warning') }}</p>
                    </div>
                @endif

                @if (session('status'))
                    <div class="mb-5 flex items-start gap-3 rounded-xl border border-success-500/30 bg-success-50 px-4 py-3 text-success-700 dark:bg-success-500/10">
                        <x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" />
                        <p>{{ session('status') }}</p>
                    </div>
                @endif

                {{ $slot ?? '' }}
                @yield('content')
            </main>

            <footer class="px-6 pb-6 text-theme-xs text-gray-400">
                Woodex OS · {{ now()->timezone('Asia/Karachi')->format('Y') }} · Woodex Interior, Lahore
            </footer>
        </div>
    </div>

    <x-admin.toasts />

    @livewireScripts
    <x-admin.pdf-preview />
</body>
</html>

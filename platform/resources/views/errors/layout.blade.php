<!DOCTYPE html>
{{--
    The error pages (403, 404, 419, 429, 500, 503), in the design of the sign-in page (resources/css/auth.css).
    An error page must open when little else does: no session, no signed-in person, no database, no Livewire.
    If this view itself fails, Laravel falls back to its own plain page.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f7f7">
    <title>@yield('title') · WOODEX OS</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script>
        (() => {
            let mode = 'system';
            try { mode = localStorage.getItem('wx.theme') || 'system'; } catch (e) {}
            const dark = mode === 'dark' || (mode !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        })();
    </script>
    @vite(['resources/css/admin.css'])
</head>
@php
    // Where "back" leads: the admin for an address under it, the front page otherwise.
    $adminPath = trim((string) config('woodex.admin.path', 'admin'), '/');
    $inAdmin = $adminPath !== '' && (request()->is($adminPath) || request()->is($adminPath.'/*'));
    $home = $inAdmin && \Illuminate\Support\Facades\Route::has('admin.dashboard') ? route('admin.dashboard') : url('/');
@endphp
<body class="wx-auth">
    <div class="wx-auth-shell">
        <header class="wx-auth-header">
            <a href="{{ $home }}" class="wx-auth-brand wx-brand" aria-label="WOODEX OS">
                <x-brand-logo subtitle="OPERATING SYSTEM" />
            </a>
        </header>
        <main class="wx-auth-main">
            <section class="wx-auth-card" aria-labelledby="error-heading" data-error-page="@yield('code')">
                <p class="wx-auth-card-eyebrow">@yield('code') · @yield('title')</p>
                <h1 id="error-heading" class="wx-auth-heading">@yield('heading')</h1>
                <p class="wx-auth-subheading">@yield('message')</p>
                <div class="wx-auth-content flex flex-wrap gap-3">
                    @hasSection('actions')
                        @yield('actions')
                    @else
                        <a href="{{ $home }}" class="wx-auth-submit flex h-11 items-center justify-center rounded-lg px-5 text-theme-sm font-medium">{{ $inAdmin ? 'Go to the dashboard' : 'Go to the front page' }}</a>
                    @endif
                </div>
                <p class="wx-auth-fallback">{{ now()->timezone('Asia/Karachi')->format('j M Y, H:i') }} (Pakistan time)</p>
            </section>
        </main>
        <footer class="wx-auth-footer"><span>Woodex Furniture · Lahore</span></footer>
    </div>
</body>
</html>

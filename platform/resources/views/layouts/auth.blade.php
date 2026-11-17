<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f7f7">
    <title>@yield('title', 'Sign in') · WOODEX OS</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script nonce="{{ Vite::cspNonce() }}">
        // The same theme as the admin (wx.theme: light, dark or system), set before the first paint.
        (() => {
            let mode = 'system';
            try { mode = localStorage.getItem('wx.theme') || 'system'; } catch (e) {}
            const dark = mode === 'dark' || (mode !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#17191c' : '#f7f7f7');
        })();
    </script>
    @vite(['resources/css/admin.css', 'resources/js/auth.js'])
</head>
<body class="wx-auth">
    <div class="wx-auth-shell">
        <header class="wx-auth-header">
            <a href="{{ route('login') }}" class="wx-auth-brand wx-brand" aria-label="WOODEX OS sign in">
                <x-brand-logo subtitle="OPERATING SYSTEM" />
            </a>
            <a href="{{ \App\Support\SyntheticPreview::enabled() ? url('/') : config('woodex.public_site_url') }}" class="wx-auth-website">Woodex Furniture <span aria-hidden="true">↗</span></a>
        </header>
        <main class="wx-auth-main">
            <section class="wx-auth-card" aria-labelledby="auth-heading">
                <p class="wx-auth-card-eyebrow">WOODEX OS</p>
                <h1 id="auth-heading" class="wx-auth-heading">@yield('heading')</h1>
                <p class="wx-auth-subheading">@yield('subheading')</p>
                @if (session('status'))
                    <div class="mt-5 rounded border border-success-500/30 bg-success-50 p-3 text-theme-sm text-success-700" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mt-5 rounded border border-error-500/30 bg-error-50 p-3 text-theme-sm text-error-700" role="alert">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="wx-auth-content">@yield('content')</div>
            </section>
        </main>
        <footer class="wx-auth-footer"><span>Woodex Furniture · Lahore</span><span>A considered space for better work.</span></footer>
    </div>
    <script nonce="{{ Vite::cspNonce() }}">
        // Native fallback for the other Fortify forms. The enhanced login prevents this handler.
        (function () {
            var busy = ['opacity-70', 'cursor-wait'];
            document.addEventListener('submit', function (event) {
                if (event.defaultPrevented) return;
                var form = event.target;
                if (form.dataset.submitting) { event.preventDefault(); return; }
                form.dataset.submitting = '1';
                form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                    button.setAttribute('aria-busy', 'true');
                    button.classList.add.apply(button.classList, busy);
                });
            });
            window.addEventListener('pageshow', function () {
                document.querySelectorAll('form[data-submitting]').forEach(function (form) {
                    delete form.dataset.submitting;
                    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                        button.removeAttribute('aria-busy');
                        button.classList.remove.apply(button.classList, busy);
                    });
                });
            });
        })();
    </script>
</body>
</html>

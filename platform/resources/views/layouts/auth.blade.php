<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-auth-glass="glass">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#242424">
    <title>@yield('title', 'Sign in') · Woodex OS</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preload" as="image" href="{{ asset('assets/img/svc-interior-design.webp') }}">
    <script>
        try { document.documentElement.dataset.authGlass = localStorage.getItem('wx.auth.glass') === 'solid' ? 'solid' : 'glass'; if (localStorage.getItem('wx.theme') === 'dark') { document.documentElement.classList.add('dark'); } } catch (e) {}
    </script>
    @vite(['resources/css/admin.css', 'resources/js/auth.js'])
</head>
<body class="wx-auth">
    <div class="wx-auth-backdrop" aria-hidden="true"></div>
    <div class="wx-auth-shell">
        <header class="wx-auth-header">
            <a href="{{ route('login') }}" class="wx-auth-brand" aria-label="Woodex OS sign in">
                <x-brand-logo subtitle="OPERATING SYSTEM" />
            </a>
            <a href="{{ \App\Support\SyntheticPreview::enabled() ? url('/') : config('woodex.public_site_url') }}" class="wx-auth-website">Woodex Interior <span aria-hidden="true">↗</span></a>
        </header>
        <main class="wx-auth-main">
            <section class="wx-auth-card" aria-labelledby="auth-heading">
                <div class="wx-auth-card-tools"><p class="wx-auth-card-eyebrow">WOODEX OS</p><button type="button" data-auth-glass-toggle aria-pressed="true" title="Switch between transparent glass and solid contrast">Glass appearance</button></div>
                <h1 id="auth-heading" class="wx-auth-heading">@yield('heading')</h1>
                <p class="wx-auth-subheading">@yield('subheading')</p>
                @if (session('status'))
                    <div class="mt-5 rounded-lg border border-success-500/30 bg-success-50 p-3 text-theme-sm text-success-700" role="status">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-error-500/30 bg-error-50 p-3 text-theme-sm text-error-700" role="alert">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="wx-auth-content">@yield('content')</div>
            </section>
        </main>
        <footer class="wx-auth-footer"><span>Woodex Interior · Lahore</span><span>A considered space for better work.</span></footer>
    </div>
    <script>
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

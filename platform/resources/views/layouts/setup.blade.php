<!DOCTYPE html>
{{-- Installer and update pages (D-58). No session, no Livewire, no menu: they must work before the database exists. --}}
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f1e36">
    <title>@yield('title', 'Setup') · Woodex OS</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/admin.css'])
</head>
<body class="min-h-full bg-gray-50 dark:bg-gray-950">
    <header class="bg-navy-900">
        <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-5 text-white">
            <x-brand-logo subtitle="OPERATING SYSTEM" />
            <span class="text-theme-xs text-navy-100/80">@yield('step')</span>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-6 py-10">
        <h1 class="font-heading text-title-sm font-normal text-gray-900 dark:text-white">@yield('heading')</h1>
        <p class="mt-2 max-w-2xl text-theme-sm text-gray-500 dark:text-gray-400">@yield('subheading')</p>

        @if (isset($errors) && $errors->any())
            <div class="mt-6 rounded-xl border border-error-500/30 bg-error-50 px-4 py-3 text-theme-sm text-error-700" role="alert">
                <p class="font-medium">Please check:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-8">
            @yield('content')
        </div>
    </main>

    <script>
        // Each form submits once: installing takes a few seconds and a second click must not start it again.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form.dataset.submitting) { event.preventDefault(); return; }
            form.dataset.submitting = '1';
            form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                button.setAttribute('aria-busy', 'true');
                button.classList.add('opacity-70', 'cursor-wait');
                if (button.dataset.busyText) { button.textContent = button.dataset.busyText; }
            });
        });
    </script>
</body>
</html>

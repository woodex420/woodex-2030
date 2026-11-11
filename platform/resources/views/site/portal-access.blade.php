@use('App\Domain\Projects\Services\PortalLinks')
@php
    $identifier = old('identifier', session()->has('portal_contact_id') && $contact ? ($contact->email ?: $contact->phone) : '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f1e36">
    <title>Your project · {{ $business->brand_name }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/site.css'])
</head>
<body class="bg-sand-50">
    <header class="bg-navy-900 text-white">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-5 py-5 md:px-8">
            <x-brand-logo subtitle="INTERIOR STUDIO" class="text-white" />
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-5 py-10 md:px-8 md:py-16">
        @if ($contact)
            <h1 class="text-3xl font-medium text-ink">Your projects</h1>
            <p class="mt-2 text-ink/70">Welcome back, {{ $contact->first_name() }}.</p>

            <ul class="mt-6 divide-y divide-line rounded-2xl border border-line bg-white">
                @foreach ($projects as $project)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="font-medium text-ink">{{ $project->name }}</p>
                            <p class="text-sm text-muted">{{ $project->code }} · {{ $project->stage->label() }} · {{ $project->progress_percent }}%</p>
                        </div>
                        <a href="{{ PortalLinks::signed('projects.portal', $project) }}" class="text-sm font-medium text-navy-900 hover:underline">Open</a>
                    </li>
                @endforeach
            </ul>

            <form method="post" action="{{ route('portal.leave') }}" class="mt-6">
                @csrf
                <button type="submit" class="text-sm text-muted hover:text-ink">Close this session</button>
            </form>
        @else
            <h1 class="text-3xl font-medium text-ink">Your project</h1>
            <p class="mt-2 max-w-xl text-ink/70">
                Tell us the phone number or the email you booked with and we will send you a six-digit code.
                There is no password to remember — the code works once.
            </p>

            <form method="post" action="{{ route('portal.code') }}" class="mt-8 rounded-2xl border border-line bg-white p-6">
                @csrf

                @if ($sent)
                    <p class="mb-4 rounded-ui bg-green-50 px-4 py-3 text-sm text-green-900" role="status">
                        If we have a project for those details, the code is on its way by email. It works for 15 minutes.
                    </p>
                @endif

                @if ($errors->code->isNotEmpty())
                    <div class="mb-4 rounded-ui bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        @foreach ($errors->code->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif

                <label class="block">
                    <span class="text-sm text-muted">Phone number or email</span>
                    <input type="text" name="identifier" value="{{ $identifier }}" required autocomplete="username"
                           class="mt-1 h-11 w-full rounded-ui border border-line bg-white px-3 text-ink" placeholder="0300 1234567 or you@example.com">
                </label>

                <button type="submit" class="mt-5 inline-flex h-11 items-center rounded-full bg-navy-900 px-6 text-sm font-medium text-white hover:bg-navy-800">Send me a code</button>
            </form>

            <form method="post" action="{{ route('portal.enter') }}" class="mt-6 rounded-2xl border border-line bg-white p-6">
                @csrf
                <h2 class="text-lg font-medium text-ink">I already have a code</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm text-muted">Phone number or email</span>
                        <input type="text" name="identifier" value="{{ $identifier }}" required autocomplete="username" class="mt-1 h-11 w-full rounded-ui border border-line bg-white px-3 text-ink">
                    </label>
                    <label class="block">
                        <span class="text-sm text-muted">Six-digit code</span>
                        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
                               class="mt-1 h-11 w-full rounded-ui border border-line bg-white px-3 text-ink tracking-[0.5em]">
                    </label>
                </div>

                <button type="submit" class="mt-5 inline-flex h-11 items-center rounded-full bg-navy-900 px-6 text-sm font-medium text-white hover:bg-navy-800">Open my project</button>
            </form>
        @endif

        <footer class="mt-12 border-t border-line pt-6 text-sm text-muted">
            <p class="font-medium text-ink">{{ $business->legal_name }}</p>
            <p>{{ $business->address }} · {{ $business->phone }} · {{ $business->email }}</p>
        </footer>
    </main>
</body>
</html>

@extends('layouts.auth')

@section('title', 'Sign in')
@section('heading', 'Welcome back')
@section('subheading', request()->attributes->get('synthetic_direct_preview') === true ? 'Explore the updated Woodex workspace.' : 'Sign in to your Woodex workspace.')

@section('content')
    @if (request()->attributes->get('synthetic_direct_preview') === true)
        <aside class="wx-auth-demo" aria-label="Direct synthetic demo — not production">
            <div class="wx-auth-demo-heading">Admin demo <span>NOT PRODUCTION</span></div>
            <p><strong>Sign-in is paused in this isolated demo.</strong></p>
            <p>Open the updated admin directly. No password or retained browser session is needed.</p>
            <p>Disposable synthetic data only. Do not enter real customer information or provider credentials. This does not sign you into production.</p>
        </aside>
        <a href="{{ route('admin.dashboard', [], false) }}" data-direct-admin class="wx-auth-submit flex h-11 w-full items-center justify-center rounded-lg text-theme-sm font-medium">Open updated admin</a>
    @else
    @php
        $syntheticPreview = \App\Support\SyntheticPreview::enabled();
        $demoCredentials = \App\Support\SyntheticPreview::credentials();
    @endphp
    @if ($syntheticPreview)
        <aside aria-label="Synthetic preview — not production" class="wx-auth-demo">
            <div class="wx-auth-demo-heading">Test workspace <span>NOT PRODUCTION</span></div>
            @if ($demoCredentials)
                <dl>
                    <dt>Email</dt><dd>{{ $demoCredentials['email'] }}</dd>
                    <dt>Password</dt><dd>{{ $demoCredentials['password'] }}</dd>
                </dl>
                <p>Test credentials are pre-filled. Select Sign in to continue. No automatic login.</p>
            @else
                <p>Use the preview account supplied for this session. Your production staff credentials will not work here.</p>
                @if (config('preview.login_email'))
                    <p>Preview email: <strong>{{ config('preview.login_email') }}</strong></p>
                @endif
                <p>The password is the separate preview password, not your production password.</p>
            @endif
            <p>Isolated synthetic data. Password-reset emails are not sent.</p>
        </aside>
    @endif
    <x-admin.google-notice />
    @if (\App\Integrations\Google\GoogleOAuth::loginEnabled())
        <a href="{{ route('google.login') }}" target="_top" class="mb-5 flex h-11 w-full items-center justify-center gap-2 rounded-lg border border-gray-300 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200">Continue with Google</a>
        <p class="mb-5 text-center text-theme-xs text-gray-500">Previously linked staff accounts only. Or use your Woodex password below.</p>
    @endif
    <form method="POST" action="{{ route('login.store', [], false) }}" class="space-y-5" data-sign-in data-two-factor-url="{{ route('two-factor.login', [], false) }}">
        <p data-sign-in-status hidden role="status" aria-live="polite" class="rounded-lg border border-gray-300 bg-gray-50 p-3 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"></p>
        @csrf
        <div>
            <label for="email" class="wx-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email', $demoCredentials['email'] ?? ($syntheticPreview ? config('preview.login_email') : null)) }}" required autofocus autocomplete="username" class="wx-input h-11">
        </div>
        <div>
            <div class="mb-1.5 flex items-center justify-between">
                <label for="password" class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                <a href="{{ route('password.request') }}" class="text-theme-xs text-navy-600 hover:text-navy-800 dark:text-gray-400">Forgot password?</a>
            </div>
            <input id="password" type="password" name="password" value="{{ $demoCredentials['password'] ?? '' }}" required autocomplete="current-password" class="wx-input h-11">
            <button type="button" data-show-password aria-controls="password" aria-pressed="false" class="mt-2 text-theme-xs font-medium text-gray-600 underline dark:text-gray-300">Show password</button>
        </div>
        <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400">
            <input type="checkbox" name="remember" class="size-4 rounded border-gray-300 text-navy-900"> Keep me signed in on this device
        </label>
        <button type="submit" class="wx-auth-submit flex h-11 w-full items-center justify-center rounded-lg text-theme-sm font-medium">Sign in</button>
    </form>
    <p class="wx-auth-fallback">Having trouble in this window? <a href="{{ route('login') }}" target="_blank" rel="noopener" class="font-medium text-gray-800 underline dark:text-gray-200">Open sign-in in a new tab</a>.</p>
    @endif
@endsection

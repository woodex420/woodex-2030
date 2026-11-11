<x-admin.card title="Google staff sign-in" description="Optional sign-in for existing staff. This does not connect company apps or give Google access to Woodex records." class="mb-6">
    @if ($googleIdentity)
        <p class="break-all text-theme-sm text-gray-700 dark:text-gray-300">Linked Google identity: <strong>{{ $googleIdentity->email }}</strong></p>
        <form method="POST" action="{{ route('google.unlink') }}" class="mt-4 space-y-3" x-data x-on:submit="if (!confirm('Remove Google sign-in for your staff account? Your Woodex password and 2FA stay unchanged.')) $event.preventDefault()">
            @csrf
            <label class="wx-label" for="google-unlink-password">Confirm your Woodex password</label>
            <input id="google-unlink-password" class="wx-input max-w-sm" type="password" name="password" autocomplete="current-password" required>
            <x-admin.button type="submit" variant="secondary">Unlink Google sign-in</x-admin.button>
        </form>
    @elseif ($googleConfigured)
        <p class="text-theme-sm text-gray-600 dark:text-gray-300">Confirm your Woodex password, then choose a Google account on Google's own page. No staff account is created automatically; confirmed Woodex 2FA is still required at sign-in.</p>
        <form method="POST" action="{{ route('google.link') }}" target="_top" class="mt-4 space-y-3">
            @csrf
            <label class="wx-label" for="google-link-password">Confirm your Woodex password</label>
            <input id="google-link-password" class="wx-input max-w-sm" type="password" name="password" autocomplete="current-password" required>
            <x-admin.button type="submit" icon="external">Link Google sign-in</x-admin.button>
        </form>
    @else
        <p class="text-theme-sm text-gray-500">Google setup is required on the server before you can link an account. Continue using your Woodex password.</p>
    @endif
    @if (! $googleLoginEnabled)<p class="mt-3 text-theme-xs text-gray-500">Google sign-in is currently disabled. Linking and company-account setup do not enable it automatically.</p>@endif
    @error('password')<p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p>@enderror
</x-admin.card>

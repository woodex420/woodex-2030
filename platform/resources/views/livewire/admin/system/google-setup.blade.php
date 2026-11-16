@if ($showGoogleSetup)
    <x-admin.modal name="showGoogleSetup" title="Connect Google" description="Company account · Official Google authorization" width="max-w-xl">
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-gray-50 text-navy-900 dark:bg-gray-800 dark:text-white"><x-icon name="globe" class="size-6" /></span>
            <div class="min-w-0"><p class="font-medium text-gray-900 dark:text-white">Google account</p><p class="break-all text-theme-xs text-gray-500">{{ $googleConnection?->email ?? 'Connect once for the company workspace' }}</p></div>
        </div>
        <p class="text-theme-sm leading-6 text-gray-600 dark:text-gray-300">Continue to Google's official sign-in and consent screen. Your Google password stays with Google; Woodex never asks for it. Authorization opens outside embedded previews and returns here.</p>
        <div class="mt-4 rounded-xl bg-gray-50 p-4 text-theme-sm dark:bg-gray-800">
            <p class="font-medium text-gray-800 dark:text-gray-200">What this connection can access</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-gray-600 dark:text-gray-300"><li>Your basic Google identity and verified email.</li><li>No Drive files, spreadsheets, email, calendar or meetings.</li><li>Company connection and staff sign-in are separate.</li></ul>
        </div>
        @if (! $googleConfigured)
            <div class="mt-5 rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-gray-700 dark:border-warning-800 dark:bg-warning-500/10 dark:text-gray-200">
                <h3 class="font-medium">Server setup required</h3>
                <p class="mt-2 text-theme-xs">Register the company OAuth app first. No Google account is connected yet.</p>
                <details class="mt-3"><summary class="cursor-pointer font-medium">Owner setup checklist</summary>
                <ol class="mt-2 list-inside list-decimal space-y-2"><li>Register a Web application in Google Cloud and configure its consent screen.</li><li>Add the exact callback shown below on the deployed site. Add test users while the app is in Testing.</li><li>Save GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in the private server environment, then reload configuration.</li><li>Enable GOOGLE_SIGN_IN_ENABLED only when staff sign-in is ready to test.</li></ol></details>
                <label class="mt-4 block text-theme-xs font-medium" for="google-callback-uri">Callback URI (not a secret)</label>
                <input id="google-callback-uri" x-init="if ($el.value.startsWith('/')) $el.value = window.location.origin + $el.value" class="wx-input mt-1 w-full font-mono text-theme-xs" readonly value="{{ config('services.google.redirect_uri') ?: route('google.callback') }}">
                <p class="mt-2 text-theme-xs">Use your canonical deployed URL, not the temporary preview host. No client secret should be pasted into this page.</p>
            </div>
        @endif
        @if ($googleConnection)
            <dl class="mt-5 space-y-2 text-theme-sm text-gray-600 dark:text-gray-300"><div><dt class="font-medium">Connection state</dt><dd>{{ match ($googleConnection->status) { 'active' => 'Connected · identity only', 'revocation_pending' => 'Disabled locally · Google revocation pending', default => 'Reconnect required' } }}</dd></div><div><dt class="font-medium">Last verified</dt><dd>{{ $googleConnection->last_checked_at?->diffForHumans() ?? 'Not yet checked' }}</dd></div><div><dt class="font-medium">Granted scopes</dt><dd class="break-all font-mono text-theme-xs">{{ implode(', ', $googleConnection->scopes) }}</dd></div></dl>
        @endif
        @if (auth()->user()->hasRole('owner'))
            @if ($googleConnection)
                <form class="mt-5" method="POST" action="{{ route('google.check') }}">@csrf<x-admin.button type="submit" variant="secondary" icon="shield">Check Google connection</x-admin.button></form>
                <form class="mt-5 space-y-3 border-t border-gray-200 pt-5 dark:border-gray-700" method="POST" action="{{ route('google.disconnect') }}" x-data x-on:submit="if (!confirm('Revoke the company Google connection? This can affect other grants for the same Google app. Staff sign-in links remain separate.')) $event.preventDefault()">
                    @csrf
                    <label for="google-disconnect-password" class="wx-label">Confirm your Woodex password to disconnect</label>
                    <input id="google-disconnect-password" type="password" name="password" class="wx-input w-full" autocomplete="current-password" required>
                    <p class="text-theme-xs text-gray-500">Tokens are removed only after Google confirms revocation. A failed attempt disables the connection locally and can be retried.</p>
                    <x-admin.button type="submit" variant="secondary">Disconnect Google</x-admin.button>
                </form>
            @elseif ($googleConfigured)
                <form class="mt-5 space-y-3" method="POST" action="{{ route('google.connect') }}" target="_top">
                    @csrf
                    <label for="google-connect-password" class="wx-label">Confirm your Woodex password</label>
                    <input id="google-connect-password" type="password" name="password" class="wx-input w-full" autocomplete="current-password" required>
                    <x-admin.button type="submit" icon="external">Continue with Google</x-admin.button>
                </form>
            @else
                <p class="mt-4 text-theme-xs text-gray-500">Reloading applies all saved server environment changes, not only Google settings. Review them first.</p>
                <x-admin.button variant="secondary" wire:click="reloadTelegramConfiguration" wire:confirm="Apply all saved environment settings and reload this page?">Reload saved server settings</x-admin.button>
                <button type="button" disabled class="mt-5 inline-flex h-10 w-full items-center justify-center rounded-lg bg-gray-100 px-4 text-theme-sm font-medium text-gray-500 dark:bg-gray-800">Complete server setup to connect</button>
            @endif
        @else
            <p class="mt-5 text-theme-sm text-gray-500">Only an owner can connect, verify or disconnect the company account.</p>
        @endif
        <x-slot:footer><x-admin.button variant="secondary" href="https://developers.google.com/identity/protocols/oauth2/web-server" target="_blank" rel="noopener noreferrer" icon="external">Google setup documentation</x-admin.button><x-admin.button variant="ghost" wire:click="$set('showGoogleSetup', false)">Close</x-admin.button></x-slot:footer>
    </x-admin.modal>
@endif

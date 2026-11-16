<div>
    <x-admin.page-header title="Security & Telegram" description="Protect your account with two-factor authentication and receive Woodex alerts on Telegram."
                         :breadcrumbs="['Profile' => null, 'Security' => null]" />

    <x-admin.google-notice />
    @include('livewire.admin.profile.google-sign-in')

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <x-admin.card title="Two-factor authentication" description="After you enable and confirm 2FA, use a 6-digit code from Google Authenticator, Microsoft Authenticator or 1Password at sign-in.">
            @if (! $enabled && ! $requiresTwoFactor)
                <p class="mb-4 text-theme-sm text-gray-600 dark:text-gray-300">2FA is optional for your account. You can use admin pages and link Telegram now. Authenticator codes are required at sign-in only after you enable and confirm 2FA.</p>
            @endif
            @if ($enabled)
                <div class="flex items-center gap-3">
                    <x-admin.badge color="success">On</x-admin.badge>
                    <span class="text-theme-sm text-gray-600 dark:text-gray-300">Your account is protected.</span>
                </div>
            @elseif ($pending || $showingSetup)
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">Scan this QR code with your authenticator app, then enter the 6-digit code to finish.</p>
                <div class="my-4 inline-block rounded-xl bg-white p-3">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">Cannot scan? In your authenticator, choose Enter a setup key, select Time based, and enter this key. This QR code is not for Telegram.</p>
                <p class="my-4 font-mono text-theme-sm text-gray-800 dark:text-white/90">{{ $setupKey }}</p>
                <p class="text-theme-xs text-gray-500">Keep this key private. Set your phone date and time to automatic. Use the latest 6-digit code from the authenticator, not a Telegram or SMS code.</p>
                <form wire:submit="confirmTwoFactor" class="flex max-w-sm gap-2">
                    <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" wire:model="code" class="wx-input" placeholder="123456" aria-label="Authentication code">
                    <x-admin.button type="submit">Confirm</x-admin.button>
                </form>
                @error('code') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
            @else
                <x-admin.badge color="warning">Off</x-admin.badge>
                <p class="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">{{ $requiresTwoFactor ? 'Your role requires two-factor authentication.' : 'Turn it on when you are ready for authenticator sign-in.' }}</p>
            @endif

            @if ($showingRecoveryCodes && $enabled)
                <div class="mt-5 rounded-xl border border-warning-500/30 bg-warning-50 p-4 dark:bg-warning-500/10">
                    <p class="text-theme-sm font-medium text-warning-700">Save these recovery codes in a safe place. Each works once.</p>
                    <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-theme-sm text-gray-800 dark:text-white/90">
                        @foreach ($user->recoveryCodes() as $recoveryCode)
                            <li>{{ $recoveryCode }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-6 border-t border-gray-100 pt-5 dark:border-gray-800">
                <label class="wx-label" for="password">Confirm your password to change these settings</label>
                <input id="password" type="password" wire:model="password" class="wx-input max-w-sm" autocomplete="current-password">
                @error('password') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                <div class="mt-3 flex flex-wrap gap-2">
                    @if (! $enabled && ! $pending && ! $showingSetup)
                        <x-admin.button icon="shield" wire:click="enableTwoFactor">Turn on 2FA</x-admin.button>
                    @endif
                    @if ($pending && ! $requiresTwoFactor)
                        <x-admin.button variant="ghost" wire:click="disableTwoFactor">Cancel setup</x-admin.button>
                    @endif
                    @if ($enabled)
                        <x-admin.button variant="secondary" icon="refresh" wire:click="regenerateRecoveryCodes">New recovery codes</x-admin.button>
                        @if (! $requiresTwoFactor)
                            <x-admin.button variant="ghost" wire:click="disableTwoFactor">Turn off</x-admin.button>
                        @endif
                    @endif
                </div>
            </div>
        </x-admin.card>

        @if ($telegramAllowed)
        <x-admin.card title="Telegram alerts" description="New leads, SLA breaches, quote views and payments, delivered to your phone.">
            @if (! $telegramConfigured)
                <div class="flex items-center gap-3">
                    <x-admin.badge color="warning">Not connected</x-admin.badge>
                    <span class="text-theme-sm text-gray-600 dark:text-gray-300">Pairing cannot start yet.</span>
                </div>
                <p class="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">Something the pairing needs is missing on the server. The connection health card below names the exact step — nothing here will work until that step passes.</p>
            @elseif ($telegram?->chat_id && $telegram->is_active)
                <div class="flex items-center gap-3">
                    <x-admin.badge color="success">Linked</x-admin.badge>
                    <span class="text-theme-sm text-gray-600 dark:text-gray-300">{{ $telegram->username ? '@'.$telegram->username : $telegram->title }} · since {{ $telegram->linked_at?->format('j M Y') }}</span>
                </div>
                <p class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">{{ $telegramLastMessageAt ? 'Last message from this chat '.$telegramLastMessageAt->diffForHumans().'.' : 'No message received from this chat yet — send /help to the bot to test the round trip.' }}</p>
                <div class="mt-4">
                    <x-admin.button variant="secondary" wire:click="unlinkTelegram">Unlink</x-admin.button>
                </div>
            @elseif ($telegram?->link_code && $telegram->link_code_expires_at?->isFuture())
                <div wire:poll.5s wire:key="telegram-pairing-{{ $telegram->link_code }}">
                    <div class="flex items-center gap-3">
                        <x-admin.badge color="warning">Waiting</x-admin.badge>
                        <span class="text-theme-sm text-gray-600 dark:text-gray-300">Scan with your phone — this page flips to Linked on its own, no reload.</span>
                    </div>

                    @if ($telegramStartUrl !== null)
                        <div class="my-4 flex flex-wrap items-start gap-5">
                            <div class="inline-block rounded-xl bg-white p-3" role="img" aria-label="QR code that opens the Woodex bot with your one-time pairing code">{!! $telegramQrSvg !!}</div>
                            <div class="min-w-56 flex-1">
                                <x-admin.button href="{{ $telegramStartUrl }}" target="_blank" rel="noopener" icon="send">Open in Telegram and tap Start</x-admin.button>
                                <label class="wx-label mt-4" for="telegram-start-url">Or copy this link to your phone</label>
                                <div class="flex gap-2">
                                    <input id="telegram-start-url" type="text" readonly value="{{ $telegramStartUrl }}" class="wx-input font-mono text-xs" onclick="this.select()">
                                    <button type="button" x-data @click="navigator.clipboard?.writeText(@js($telegramStartUrl)); $el.textContent = 'Copied'; setTimeout(() => $el.textContent = 'Copy', 1500)" class="shrink-0 rounded-lg border border-gray-300 px-3 text-theme-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Copy</button>
                                </div>
                            </div>
                        </div>
                    @else
                        <p class="my-4 text-theme-sm text-warning-700">The QR and link cannot be built — the bot username is missing (see the health card). The typed code below still works.</p>
                    @endif

                    <p class="text-theme-sm text-gray-600 dark:text-gray-300">Prefer typing? Send this message to the bot:</p>
                    <p class="my-3 inline-block rounded-xl bg-navy-900 px-4 py-3 font-mono text-lg tracking-widest text-white">/start {{ $telegram->link_code }}</p>
                    <p class="text-theme-xs text-gray-500">
                        <span x-data="{ left: {{ max(0, (int) now()->diffInSeconds($telegram->link_code_expires_at, false)) }} }"
                              x-init="setInterval(() => { if (left > 0) left-- }, 1000)"
                              x-text="left > 0 ? 'Code expires in ' + String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0') : 'This code has expired — generate a new one'">Code expires {{ $telegram->link_code_expires_at->diffForHumans() }}</span>
                        (at {{ $telegram->link_code_expires_at->format('H:i') }}). One code links one chat, once.
                    </p>
                    <p class="mt-2 text-theme-xs text-gray-500">Nothing happened? Work down the connection health card — the first failing step is the one to fix.</p>
                    <div class="mt-3">
                        <x-admin.button variant="secondary" wire:click="createTelegramCode">Generate a new code</x-admin.button>
                    </div>
                </div>
            @else
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">Link your Telegram account once: you will get a QR to scan with your phone — one tap, nothing to type.</p>
                <div class="mt-4">
                    <x-admin.button icon="send" wire:click="createTelegramCode">Link Telegram</x-admin.button>
                </div>
            @endif
        </x-admin.card>
        @else
            <x-admin.card title="In-app notifications" description="Telegram is available to the owner only. Your in-app notifications remain available." />
        @endif
    </div>

    @if ($telegramAllowed)
    {{-- Slice L: the health ladder. Every row is read from where the truth lives; the first failing step is the blocker. --}}
    <x-admin.card class="mt-6" title="Telegram connection health" description="Each step is checked live. When pairing stalls, fix the first step that fails — the ones after it cannot pass until it does.">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($telegramHealth as $step)
                <li class="flex items-start gap-3 py-3" wire:key="telegram-health-{{ $step['key'] }}">
                    <x-admin.badge :color="$step['ok'] ? 'success' : 'warning'">{{ $step['ok'] ? 'OK' : 'Fix' }}</x-admin.badge>
                    <div class="min-w-0">
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $step['label'] }}</p>
                        <p class="text-theme-sm text-gray-600 dark:text-gray-300">{{ $step['detail'] }}</p>
                        @if ($step['hint'] !== null)
                            <p class="text-theme-xs text-gray-500">{{ $step['hint'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-admin.card>
    @endif
</div>

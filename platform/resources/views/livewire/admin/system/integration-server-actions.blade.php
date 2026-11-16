                        @if ($provider['key'] === 'google_account')
                            <x-admin.button size="sm" icon="link" wire:click="openGoogleSetup">{{ $googleConnection ? 'Manage connection' : 'Connect Google' }}</x-admin.button>
                            <x-admin.button variant="ghost" size="sm" :href="route('admin.profile.security')" wire:navigate>Set up staff sign-in</x-admin.button>
                        @elseif ($provider['key'] === 'telegram')
                            <x-admin.button size="sm" icon="link" wire:click="setTelegramWebhook" wire:loading.attr="disabled">Connect webhook</x-admin.button>
                            <x-admin.button variant="secondary" size="sm" wire:click="checkTelegramConnection" wire:loading.attr="disabled">Check live connection</x-admin.button>
                            <x-admin.button variant="secondary" size="sm" icon="send" wire:click="testTelegram">Send me a test</x-admin.button>
                            <x-admin.button variant="ghost" size="sm" :href="route('admin.profile.security')" wire:navigate>Link your account</x-admin.button>
                            @if (auth()->user()->hasRole('owner'))
                                <x-admin.button variant="ghost" size="sm" wire:click="checkTelegramConfiguration">Check server configuration</x-admin.button>
                            @endif
                        @elseif ($provider['key'] === 'whatsapp')
                            <x-admin.button variant="secondary" size="sm" icon="settings" :href="route('admin.settings', ['tab' => 'whatsapp'])" wire:navigate>Set up</x-admin.button>
                            <x-admin.button variant="secondary" size="sm" icon="send" wire:click="testWhatsApp" wire:loading.attr="disabled">Test connection</x-admin.button>
                            <x-admin.button variant="secondary" size="sm" icon="message" :href="route('admin.whatsapp.templates')" wire:navigate>Templates</x-admin.button>
                        @elseif ($provider['key'] === 'mail')
                            <x-admin.button variant="secondary" size="sm" icon="mail" wire:click="testMail">Send me a test email</x-admin.button>
                        @elseif ($provider['key'] === 'legacy')
                            <x-admin.button variant="secondary" size="sm" icon="database" wire:click="testLegacy">Test connection</x-admin.button>
                            @can('system.import')<x-admin.button variant="ghost" size="sm" :href="route('admin.data-import')" wire:navigate>Data import</x-admin.button>@endcan
                        @else
                            <x-admin.button variant="secondary" size="sm" :href="route('admin.system.health')" wire:navigate>System health</x-admin.button>
                        @endif

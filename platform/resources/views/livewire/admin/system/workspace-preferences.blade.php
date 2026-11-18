<div>
    <x-admin.page-header title="Workspace preferences"
                         description="How WOODEX OS looks and behaves for you. Each person sets their own; nothing here changes what anybody may see or do."
                         :breadcrumbs="['System' => null, 'Workspace preferences' => null]" />

    @if ($welcome)
        {{-- A first sign-in is led here (App\Support\WorkspacePreferences::home): three steps, each with its state. --}}
        <x-admin.card class="mb-6" :title="$greeting" :description="$signedIn" data-welcome>
            <ol class="space-y-4 text-theme-sm text-gray-700 dark:text-gray-200">
                <li class="flex items-start gap-3" data-welcome-step="two-factor">
                    <x-icon :name="$twoFactorOn ? 'check-circle' : 'shield'" class="mt-0.5 size-5 shrink-0 {{ $twoFactorOn ? 'text-success-600' : 'text-warning-600' }}" />
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">1. Protect your sign-in</p>
                        @if ($twoFactorOn)
                            <p>Two-factor sign-in is on: a code from your phone is asked for at every sign-in.</p>
                        @else
                            <p>{{ $twoFactorRequired ? 'Your role needs two-factor sign-in: a code from your phone at every sign-in.' : 'Two-factor sign-in is off. It is not required for your role, and it is the best protection your account can have.' }}
                                <a href="{{ route('admin.profile.security') }}" wire:navigate class="font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300">Switch it on</a></p>
                        @endif
                    </div>
                </li>
                <li class="flex items-start gap-3" data-welcome-step="preferences">
                    <x-icon name="settings" class="mt-0.5 size-5 shrink-0 text-gray-500" />
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">2. Make the workspace yours</p>
                        <p>Choose below how it looks and which screen opens when you sign in.</p>
                    </div>
                </li>
                <li class="flex items-start gap-3" data-welcome-step="screens">
                    <x-icon name="layers" class="mt-0.5 size-5 shrink-0 text-gray-500" />
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-white">3. See what you can open</p>
                        <p>Your menu holds these screens. The rest of WOODEX OS is told on <a href="{{ route('admin.system.features') }}" wire:navigate class="font-medium text-brand-600 underline-offset-2 hover:underline dark:text-brand-300">Feature status</a>.</p>
                        <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                            @foreach ($menu as $group)
                                <div>
                                    <dt class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase">{{ $group['title'] }}</dt>
                                    <dd class="flex flex-wrap gap-x-3 gap-y-1">
                                        @foreach ($group['items'] as $item)
                                            <a href="{{ route($item['route']) }}" class="text-brand-600 underline-offset-2 hover:underline dark:text-brand-300"
                                               @unless (in_array($item['route'], \App\Support\AdminNavigation::FULL_DOCUMENT, true)) wire:navigate @endunless>{{ $item['label'] }}</a>
                                        @endforeach
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </li>
            </ol>
        </x-admin.card>
    @endif

    <form wire:submit="save" data-unsaved class="space-y-6">
        <x-admin.card title="Appearance" description="Kept with your account. It is used in every browser where you have not switched by hand with the buttons in the menu under your name.">
            <x-admin.field label="Appearance" error="appearance">
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-theme-sm text-gray-700 dark:text-gray-200">
                    @foreach ($appearances as $value => $label)
                        <label class="flex items-center gap-2"><input type="radio" name="appearance" wire:model="appearance" value="{{ $value }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </x-admin.field>
        </x-admin.card>

        <x-admin.card title="Keys" description="A single key can act from anywhere: N starts a lead, Q a quotation, G then D opens the dashboard, / goes to the search. Ctrl K opens the search either way.">
            <label class="flex items-start gap-3 text-theme-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" wire:model="shortcuts" class="mt-0.5 size-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                <span><span class="font-medium text-gray-900 dark:text-white">Single keys act as shortcuts</span><br>Switch this off if you dictate, or if keys pressed by accident take you away from your work.</span>
            </label>
        </x-admin.card>

        <x-admin.card title="After sign-in" description="The screen that opens when you sign in. Only screens of your own menu can be chosen.">
            <x-admin.field label="Start on" for="start" hint="A link you followed before signing in still opens that link.">
                <select id="start" wire:model="start" class="wx-input sm:max-w-md">
                    <option value="">Workspace · Dashboard</option>
                    @foreach ($screens as $id => $screen)
                        @continue($id === 'dashboard')
                        <option value="{{ $id }}">{{ $screen['label'] }}</option>
                    @endforeach
                </select>
            </x-admin.field>
        </x-admin.card>

        <div class="flex flex-wrap items-center gap-3">
            <x-admin.button type="submit" icon="check">Save preferences</x-admin.button>
            <x-admin.button variant="secondary" :href="route($opens['route'])" wire:navigate data-open-start>Open {{ \Illuminate\Support\Str::after($opens['label'], ' · ') }}</x-admin.button>
        </div>
    </form>
</div>

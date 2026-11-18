<div>
    <x-admin.page-header title="Integrations" description="One place for the apps your business actually uses. A saved credential is not proof of delivery."
                         :breadcrumbs="['System' => null, 'Integrations' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="refresh" wire:click="checkAllConnectors" wire:loading.attr="disabled">Check all now</x-admin.button>
            <x-admin.button icon="plus" wire:click="openWebhooks">Add any app</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>
    <x-admin.google-notice />
    @if (session('connector_status'))<p class="mb-4 text-success-600">{{ session('connector_status') }}</p>@endif
    @if (session('connector_error'))<p class="mb-4 text-error-600" role="alert">{{ session('connector_error') }}</p>@endif
    @error('password')<p class="mb-4 text-error-600" role="alert">{{ $message }}</p>@enderror

    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <nav class="flex flex-wrap gap-2" aria-label="Integration categories">
            @foreach (['all' => 'All apps'] + $groups as $key => $label)
                <button type="button" wire:click="$set('appCategory', '{{ $key }}')" aria-pressed="{{ $appCategory === $key ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-2 text-theme-sm font-medium', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $appCategory === $key, 'border border-gray-200 bg-white text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300' => $appCategory !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
        <input type="search" wire:model.live.debounce.300ms="appSearch" class="wx-input w-full sm:w-64" placeholder="Search apps…" aria-label="Search integrations">
    </div>

    @php $looks = ['connected' => ['success', 'Connected'], 'check' => ['warning', 'Needs check'], 'error' => ['error', 'Error'], 'off' => ['gray', 'Not connected']]; @endphp
    @forelse ($providers as $provider)
        @if ($loop->first)<div class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">@endif
        <x-admin.card :padding="false" class="flex flex-col" wire:key="provider-{{ $provider['key'] }}">
            <div class="flex h-full flex-col p-5">
                <div class="flex items-center justify-between gap-3">
                    <span class="flex size-12 items-center justify-center rounded-xl bg-gray-50 text-navy-800 dark:bg-white/5 dark:text-white"><x-icon :name="$provider['icon']" class="size-6" /></span>
                    <x-admin.badge :color="$looks[$provider['state']][0]">{{ $provider['state_label'] ?? $looks[$provider['state']][1] }}</x-admin.badge>
                </div>
                <p class="mt-4 text-theme-xs uppercase tracking-wide text-gray-400">{{ $groups[$provider['group']] }}</p>
                <h2 class="mt-1 text-theme-lg font-medium text-gray-900 dark:text-white">{{ $provider['name'] }}</h2>
                <p class="mt-2 flex-1 text-theme-sm text-gray-500 dark:text-gray-400">{{ $provider['benefit'] }}</p>
                @if ($provider['account'])<p class="mt-3 truncate text-theme-sm font-medium text-gray-700 dark:text-gray-200">{{ $provider['account'] }}</p>@endif
                <p class="mt-2 text-theme-xs text-gray-500">{{ $provider['state_note'] }}</p>
                @if ($provider['last_error'])<p class="mt-2 break-words text-theme-xs text-error-600" role="alert">{{ $provider['last_error'] }}</p>@endif
                <div class="mt-5 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    @if ($provider['kind'] === 'connector' || $provider['kind'] === 'workspace_app')
                        @php $connectionKey = $provider['kind'] === 'workspace_app' ? 'google_workspace' : $provider['key']; @endphp
                        @if (auth()->user()->hasRole('owner'))
                            <x-admin.button size="sm" wire:click="openConnector('{{ $connectionKey }}')">{{ $provider['account'] ? 'Reconnect' : 'Connect' }}</x-admin.button>
                        @endif
                        @if ($provider['account'])
                            <x-admin.button variant="secondary" size="sm" wire:click="checkConnector('{{ $connectionKey }}')" wire:loading.attr="disabled">Test</x-admin.button>
                            @if (auth()->user()->hasRole('owner'))
                                <x-admin.button variant="ghost" size="sm" wire:click="disconnectConnector('{{ $connectionKey }}')" wire:confirm="Remove this connection? Shared Workspace access is removed for both Gmail and Drive.">Remove</x-admin.button>
                            @endif
                        @endif
                    @elseif ($provider['kind'] === 'mcp')
                        <x-admin.button size="sm" wire:click="openMcpSetup">Set up MCP bridge</x-admin.button>
                    @elseif ($provider['kind'] === 'webhooks')
                        <x-admin.button size="sm" wire:click="openWebhooks">Manage endpoints</x-admin.button>
                    @else
                        @include('livewire.admin.system.integration-server-actions')
                    @endif
                    <x-admin.button variant="ghost" size="sm" wire:click="openDetails('{{ $provider['key'] }}')">Details</x-admin.button>
                </div>
                @php $card = $provider; @endphp
                @include('livewire.admin.system.telegram-diagnostics')
                @if ($provider['key'] === 'whatsapp' && $whatsappStatus !== null)
                    <div class="mt-3 text-theme-xs" role="status">
                        <p>{{ $whatsappStatus['ok'] ? 'Connected · Meta recognises the number' : $whatsappStatus['error'] }}</p>
                        @if ($whatsappStatus['phone'] || $whatsappStatus['name'])<p>{{ $whatsappStatus['phone'] }} · {{ $whatsappStatus['name'] }}</p>@endif
                        @if ($whatsappStatus['quality'])<p>Quality: {{ $whatsappStatus['quality'] }}</p>@endif
                    </div>
                @endif
            </div>
        </x-admin.card>
        @if ($loop->last)</div>@endif
    @empty
        <x-admin.empty icon="search" title="No integrations match your search" description="Try another app name or category." />
    @endforelse

    @if ($showDetails && $detail)
        <x-admin.modal name="showDetails" :title="$detail['name']" :description="$detail['benefit']">
            <p class="mb-4 text-theme-sm text-gray-600 dark:text-gray-300">{{ $detail['detail'] }}</p>
            <dl class="space-y-3 text-theme-sm">
                @foreach ($detail['lines'] as [$label, $value])
                    <div><dt class="text-gray-500">{{ $label }}</dt><dd class="break-words text-gray-800 dark:text-gray-200">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($detail['missing'])<p class="mt-4 text-theme-sm text-warning-600">{{ $detail['missing'] }}</p>@endif
            @if ($detail['help'])<p class="mt-4 text-theme-sm text-gray-500">{{ $detail['help'] }}</p>@endif
            @if ($detail['scopes'])
                <h3 class="mt-4 font-medium">Granted scopes</h3>
                @foreach ($detail['scopes'] as $scope)<p class="break-all text-theme-xs text-gray-500">{{ $scope }}</p>@endforeach
            @endif
        </x-admin.modal>
    @endif
    @if ($showMcpSetup) @include('livewire.admin.system.mcp-setup') @endif
    @include('livewire.admin.system.google-setup')
    @include('livewire.admin.system.integration-connector-form')
    @if ($showWebhooks) @include('livewire.admin.system.integration-webhooks') @endif
    @include('livewire.admin.system.integration-log')
</div>

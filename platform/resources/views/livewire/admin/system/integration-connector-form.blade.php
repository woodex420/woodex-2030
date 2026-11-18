    @if ($showConnectorModal && $connectorForm)
        <x-admin.modal name="showConnectorModal" :title="'Connect '.$connectorForm['name']" description="The provider is asked first; nothing is stored until it answers.">
            <form wire:submit="connectConnector" class="space-y-4">
                @foreach ($connectorForm['fields'] as $field)
                    <x-admin.field :label="$field['label']" :for="'connector-'.$field['name']">
                        <input id="connector-{{ $field['name'] }}" type="{{ $field['type'] }}" wire:model="connectorInput.{{ $field['name'] }}"
                               placeholder="{{ $field['placeholder'] }}" @if ($field['type'] !== 'password') autocomplete="off" @else autocomplete="new-password" @endif
                               class="wx-input w-full" @if ($field['required']) required @endif>
                    </x-admin.field>
                @endforeach
                @error('connectorInput')<p class="text-theme-xs text-error-600" role="alert">{{ $message }}</p>@enderror
                <p class="text-theme-xs text-gray-500">Credentials are encrypted with the application key and never shown again. Disconnect deletes the stored copy.</p>
                <div class="flex justify-end gap-3 pt-2">
                    <x-admin.button type="button" variant="secondary" wire:click="$set('showConnectorModal', false)">Cancel</x-admin.button>
                    <x-admin.button type="submit" icon="link" wire:loading.attr="disabled">Test and connect</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    @endif

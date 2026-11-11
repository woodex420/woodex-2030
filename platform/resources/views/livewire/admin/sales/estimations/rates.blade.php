<div class="space-y-5">
    <x-admin.card title="Public estimator service rates" description="Draft edits do not change prices. Publish explicitly to update the existing public-v1 file and staff/bot service rates. Public catalogue add-ons are preserved; they are not the staff quotation catalogue.">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-theme-sm {{ ($rates['consistent'] ?? false) ? 'text-success-700' : 'text-warning-700' }}">{{ ($rates['consistent'] ?? false) ? 'Public file and database are in sync' : 'Needs attention: public file and database differ, or the rate book cannot be read' }}</span>
            <a href="/estimator/" target="_blank" rel="noopener" class="text-theme-sm underline">Open public estimator</a>
        </div>
        @if (isset($rates['error']))<p role="alert" class="mt-3 text-error-600">{{ $rates['error'] }}</p>@endif
        @error('book')<p role="alert" class="mt-3 text-error-600">{{ $message }}</p>@enderror
        @if ($notice)<p role="status" class="mt-3 text-success-700">{{ $notice }}</p>@endif
        <div class="mt-4 flex flex-wrap gap-3">
            <x-admin.button wire:click="publishRates" wire:loading.attr="disabled" wire:confirm="Publish these service prices to the public estimator and staff/bot rate book? Existing saved estimates will not be repriced.">Publish rates</x-admin.button>
            <x-admin.button variant="secondary" wire:click="reloadRates" wire:confirm="Discard unsaved rate changes and reload the published book?">Reload published rates</x-admin.button>
        </div>
        <p class="mt-3 text-theme-xs text-gray-500">To reconcile drift, reload/check the published file and explicitly publish it. Existing saved estimates and quotations are not rewritten.</p>
    </x-admin.card>
    @foreach (($book['services'] ?? []) as $i => $row)
        <x-admin.card :title="($row['label'] ?: 'New service').' · '.($i + 1)" wire:key="rate-service-{{ $i }}">
            <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-admin.field label="Service name" :for="'rate-label-'.$i"><input id="rate-label-{{ $i }}" wire:model="book.services.{{ $i }}.label" maxlength="80" class="wx-input"></x-admin.field>
                <x-admin.field label="Stable key (generated for new services)" :for="'rate-key-'.$i"><input id="rate-key-{{ $i }}" value="{{ $row['key'] }}" readonly class="wx-input" aria-readonly="true"></x-admin.field>
                <x-admin.field label="Unit" :for="'rate-unit-'.$i"><select id="rate-unit-{{ $i }}" wire:model="book.services.{{ $i }}.unit" class="wx-input"><option value="sqft">Square feet</option><option value="views">3D views</option></select></x-admin.field>
                <x-admin.field label="Quantity label" :for="'rate-qty-'.$i"><input id="rate-qty-{{ $i }}" wire:model="book.services.{{ $i }}.unitLabel" maxlength="60" class="wx-input"></x-admin.field>
                <x-admin.field label="Short description" :for="'rate-hint-'.$i"><input id="rate-hint-{{ $i }}" wire:model="book.services.{{ $i }}.hint" maxlength="200" class="wx-input"></x-admin.field>
                @foreach (['essential', 'standard', 'premium'] as $finish)
                    <x-admin.field :label="ucfirst($finish).' (PKR / unit)'" :for="'rate-'.$i.'-'.$finish"><input id="rate-{{ $i }}-{{ $finish }}" wire:model="book.services.{{ $i }}.rates.{{ $finish }}" type="number" min="0" max="10000000" step="1" inputmode="numeric" class="wx-input tabular-nums"></x-admin.field>
                @endforeach
            </div>
            <p class="mt-2 text-theme-xs text-gray-500">Essential ≤ Standard ≤ Premium. Whole PKR only. Zero is allowed; negative or out-of-range prices are rejected.</p>
            <x-admin.button variant="ghost" wire:click="removeService({{ $i }})" wire:confirm="Remove from this draft? Publishing deactivates the service; historical estimates remain.">Remove service</x-admin.button>
        </x-admin.card>
    @endforeach
    <x-admin.button variant="secondary" icon="plus" wire:click="addService">Add service</x-admin.button>
    <x-admin.card title="Finish labels and estimator terms">
        <div class="grid gap-4 sm:grid-cols-3">
        @foreach (($book['finishes'] ?? []) as $i => $finish)
            <div class="space-y-3">
                <x-admin.field :label="ucfirst($finish['key']).' label'" :for="'finish-label-'.$i"><input id="finish-label-{{ $i }}" wire:model="book.finishes.{{ $i }}.label" maxlength="40" class="wx-input"></x-admin.field>
                <x-admin.field label="Finish description" :for="'finish-hint-'.$i"><input id="finish-hint-{{ $i }}" wire:model="book.finishes.{{ $i }}.hint" maxlength="160" class="wx-input"></x-admin.field>
            </div>
        @endforeach
        </div>
        <div class="mt-4 space-y-3">@foreach (($book['terms'] ?? []) as $i => $term)<x-admin.field :label="'Term '.($i + 1)" :for="'rate-term-'.$i"><input id="rate-term-{{ $i }}" wire:model="book.terms.{{ $i }}" maxlength="400" class="wx-input"></x-admin.field>@endforeach</div>
    </x-admin.card>
    <x-admin.card title="Named published rate books" description="Save a backup of the currently published prices, not unsaved draft edits. Loading a named book only fills this editor; it does not publish.">
        <div class="grid items-end gap-3 sm:grid-cols-2">
            <x-admin.field label="Book name" for="bookName"><input id="bookName" wire:model="bookName" maxlength="60" list="named-books" class="wx-input"><datalist id="named-books">@foreach (($rates['templates'] ?? []) as $snapshot)<option value="{{ $snapshot['name'] }}">{{ $snapshot['at'] }}</option>@endforeach</datalist></x-admin.field>
            <div class="flex flex-wrap gap-2">
                <x-admin.button variant="secondary" wire:click="namedBook('save')">Save published book</x-admin.button>
                <x-admin.button variant="secondary" wire:click="namedBook('apply')" wire:confirm="Replace unsaved rate edits with this named book? Nothing will publish yet.">Load as draft</x-admin.button>
                <x-admin.button variant="ghost" wire:click="namedBook('delete')" wire:confirm="Delete this named backup? Published prices stay unchanged.">Delete backup</x-admin.button>
            </div>
        </div>
        <ul class="mt-4 space-y-1 text-theme-sm text-gray-500">@foreach (($rates['templates'] ?? []) as $snapshot)<li>{{ $snapshot['name'] }} · {{ $snapshot['services'] }} services · {{ $snapshot['at'] }}</li>@endforeach</ul>
    </x-admin.card>
</div>

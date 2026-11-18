<x-admin.modal name="showWebhooks" title="Connect any app" description="Signed HTTPS webhooks for Zapier, Make or your own app. Deliveries run on the database queue, with up to three attempts." width="max-w-4xl">
    @if ($newWebhookSecret)
        <div class="mb-5 rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-warning-800">
            <p class="font-medium">Save this signing secret now — it is only shown once.</p>
            <code class="mt-2 block break-all select-all">{{ $newWebhookSecret }}</code>
            <p class="mt-2">Verify X-Woodex-Signature as sha256= + HMAC-SHA256 of the exact request body using this secret. Deduplicate using X-Woodex-Delivery; retries keep the same ID.</p>
        </div>
    @endif
    @if (auth()->user()->hasRole('owner'))
        <form wire:submit="createWebhook" class="mb-6 space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-admin.field label="App name" for="webhook-name" error="webhookName"><input id="webhook-name" wire:model="webhookName" class="wx-input" placeholder="Zapier — new enquiries"></x-admin.field>
                <x-admin.field label="Endpoint URL" for="webhook-url" error="webhookUrl"><input id="webhook-url" type="url" wire:model="webhookUrl" class="wx-input" placeholder="https://hooks.zapier.com/…"></x-admin.field>
            </div>
            <p class="text-theme-xs text-gray-500">Paste the Catch Hook URL from Zapier or Custom webhook URL from Make. Only public HTTPS destinations are accepted. Selected events share client data with this destination.</p>
            <fieldset class="flex flex-wrap gap-4"><legend class="mb-2 text-theme-sm font-medium">Send these events</legend>
                @foreach ($eventOptions as $event => $label)
                    <label class="flex items-center gap-2 text-theme-sm"><input type="checkbox" wire:model="webhookEvents" value="{{ $event }}">{{ $label }}</label>
                @endforeach
            </fieldset>
            @error('webhookEvents')<p class="text-error-600">{{ $message }}</p>@enderror
            <x-admin.button type="button" wire:click="createWebhook" wire:target="createWebhook" icon="plus" wire:loading.attr="disabled">Add endpoint</x-admin.button>
        </form>
    @endif
    <div class="space-y-3">
        @forelse ($webhooks as $webhook)
            <div wire:key="webhook-{{ $webhook->id }}" class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <div class="flex items-center justify-between gap-2"><h3 class="font-medium">{{ $webhook->name }}</h3><x-admin.badge :color="$webhook->is_active ? 'info' : 'gray'">{{ $webhook->is_active ? 'Listening' : 'Paused' }}</x-admin.badge></div>
                <p class="mt-1 break-all text-theme-xs text-gray-500">{{ $webhook->url }}</p>
                <p class="mt-1 text-theme-xs text-gray-500">{{ implode(' · ', $webhook->events ?? []) }} · {{ $webhook->deliveries_count }} deliveries · secret {{ $webhook->secretSuffix() }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-admin.button size="sm" variant="secondary" wire:click="testWebhook({{ $webhook->id }})" wire:loading.attr="disabled">Test</x-admin.button>
                    @if (auth()->user()->hasRole('owner'))
                        <x-admin.button size="sm" variant="ghost" wire:click="toggleWebhook({{ $webhook->id }})">{{ $webhook->is_active ? 'Pause' : 'Resume' }}</x-admin.button>
                        <x-admin.button size="sm" variant="ghost" wire:click="rotateWebhookSecret({{ $webhook->id }})" wire:confirm="Rotate the secret? Your receiving app must be updated immediately.">Rotate secret</x-admin.button>
                        <x-admin.button size="sm" variant="ghost" wire:click="deleteWebhook({{ $webhook->id }})" wire:confirm="Remove this endpoint and its delivery history? Pending deliveries will not be sent.">Remove</x-admin.button>
                    @endif
                </div>
            </div>
        @empty
            <p class="py-4 text-theme-sm text-gray-500">No endpoints yet. Add an app above — no test delivery is sent until you press Test.</p>
        @endforelse
    </div>
    <p class="mt-5 text-theme-xs text-gray-500">An enabled endpoint is configuration, not proof of delivery. Queued tests need a running queue worker. HTTP success confirms the receiving server accepted the request, not that its downstream workflow completed. This is outbound event delivery, not a two-way chat channel.</p>
    <h3 class="mt-6 font-medium">Delivery history · latest 30</h3>
    <div class="mt-3 overflow-x-auto" wire:poll.10s>
        <table class="w-full text-left text-theme-xs">
            <thead><tr class="border-b border-gray-100"><th class="p-2">App / event</th><th class="p-2">Outcome</th><th class="p-2">Attempts</th><th class="p-2">Time</th></tr></thead>
            <tbody>
                @forelse ($deliveries as $delivery)
                    <tr wire:key="delivery-{{ $delivery->id }}" class="border-b border-gray-100 dark:border-gray-800">
                        <td class="p-2">{{ $delivery->webhook?->name }}<br><span class="text-gray-500">#{{ $delivery->id }} · {{ $delivery->event }}</span></td>
                        <td class="p-2">{{ ucfirst($delivery->status) }}{{ $delivery->response_status ? ' · HTTP '.$delivery->response_status : '' }}@if ($delivery->error)<p class="text-error-600">{{ $delivery->error }}</p>@endif</td>
                        <td class="p-2">{{ $delivery->attempts }}</td><td class="p-2">{{ $delivery->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-3 text-gray-500">Nothing delivered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.modal>

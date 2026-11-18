<div>
    <x-admin.page-header
        title="WhatsApp templates"
        description="Meta owns the wording of every message sent outside the 24-hour window. Sync what it holds, then decide which template carries which moment."
        :breadcrumbs="['System' => null, 'Integrations' => route('admin.integrations'), 'WhatsApp templates' => null]">
        <x-slot:actions>
            @can('integrations.manage')
                <x-admin.button variant="secondary" icon="refresh" wire:click="sync" wire:loading.attr="disabled">Sync from Meta</x-admin.button>
                <x-admin.button icon="plus" wire:click="showNewForm">New template</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    @if (! $connected)
        <x-admin.card class="mb-6">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                WhatsApp is not connected yet. Add the token and phone number id in
                <a href="{{ route('admin.settings', ['tab' => 'whatsapp']) }}" wire:navigate class="font-medium text-navy-700 underline dark:text-white">Settings → WhatsApp</a>,
                then come back and sync. The WhatsApp Business Account id is what lets templates be read and submitted.
            </p>
        </x-admin.card>
    @elseif (! $canManageTemplates)
        <x-admin.card class="mb-6">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                Messaging works, but templates live on the business account: add the
                <strong>WhatsApp Business Account id</strong> in
                <a href="{{ route('admin.settings', ['tab' => 'whatsapp']) }}" wire:navigate class="font-medium text-navy-700 underline dark:text-white">Settings → WhatsApp</a>
                to read and submit them.
            </p>
        </x-admin.card>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['' => 'All ('.$counts['all'].')', 'approved' => 'Approved ('.$counts['approved'].')', 'pending' => 'In review', 'rejected' => 'Rejected'] as $value => $label)
            <button type="button" wire:click="$set('status', '{{ $value }}')"
                    @class(['rounded-full border px-3 py-1.5 text-theme-xs font-medium transition', 'border-navy-900 bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $status === $value, 'border-gray-200 text-gray-600 hover:border-gray-300 dark:border-gray-800 dark:text-gray-300' => $status !== $value])>{{ $label }}</button>
        @endforeach
    </div>

    <x-admin.card :padding="false">
        @if ($templates->isEmpty())
            <x-admin.empty icon="message" title="No templates yet"
                           description="Press Sync from Meta to read the templates the business account already holds, or write a new one and send it for approval.">
                <x-admin.button variant="secondary" icon="refresh" wire:click="sync" wire:loading.attr="disabled">Sync from Meta</x-admin.button>
                <x-admin.button icon="plus" wire:click="showNewForm">New template</x-admin.button>
            </x-admin.empty>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-left text-theme-sm">
                    <thead class="border-b border-gray-200 text-theme-xs uppercase tracking-wide text-gray-500 dark:border-gray-800">
                        <tr>
                            <th class="px-5 py-3 font-medium">Template</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Used for</th>
                            <th class="px-5 py-3 font-medium">Synced</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($templates as $template)
                            <tr wire:key="template-{{ $template->id }}" @class(['opacity-70' => ! $template->isUsable()])>
                                <td class="px-5 py-4 align-top">
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $template->name }}</p>
                                    <p class="text-theme-xs text-gray-500">{{ $template->language }}@if ($template->category) · {{ \Illuminate\Support\Str::headline($template->category) }}@endif</p>
                                    @if ($template->body)
                                        <p class="mt-2 max-w-xl rounded-lg bg-gray-50 px-3 py-2 text-theme-xs whitespace-pre-line text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">{{ $template->body }}</p>
                                    @endif
                                    @if ($template->status === \App\Domain\System\Enums\WhatsAppTemplateStatus::Rejected && $template->rejected_reason)
                                        <p class="mt-2 text-theme-xs text-error-600">Meta: {{ $template->rejected_reason }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <x-admin.badge :color="$template->status->color()">{{ $template->status->label() }}</x-admin.badge>
                                    @if ($template->variables() !== [])
                                        <p class="mt-1.5 text-theme-xs text-gray-500">{{ count($template->variables()) }} variable(s): {{ implode(', ', $template->variableTokens()) }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <label class="sr-only" for="event-{{ $template->id }}">Used for</label>
                                    <select id="event-{{ $template->id }}" wire:change="map({{ $template->id }}, $event.target.value)"
                                            class="wx-input max-w-56" @disabled(! $template->isUsable() && blank($template->event_key))>
                                        <option value="">Not used automatically</option>
                                        @foreach ($events as $value => $label)
                                            <option value="{{ $value }}" @selected($template->event_key === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if ($template->event_key && ! $template->isUsable())
                                        <p class="mt-1.5 text-theme-xs text-warning-600">Mapped, but Meta no longer allows this template to be sent.</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-theme-xs text-gray-500">{{ $template->synced_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <p class="mt-4 text-theme-xs text-gray-500">
        Only an approved template can be mapped. The quotation link is sent as the template's variable, which is
        why the body needs one: <code class="rounded bg-gray-100 px-1 dark:bg-white/10">@{{1}}</code> is the
        client name and <code class="rounded bg-gray-100 px-1 dark:bg-white/10">@{{2}}</code> the link.
    </p>

    @if ($showNew)
        <x-admin.modal name="showNew" title="New template" description="Meta reviews every template before it can be sent. Approval usually takes under a day." submit="submit">
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-admin.field label="Name" for="new.name" hint="Lowercase, numbers and underscores." class="sm:col-span-1">
                        <input id="new.name" type="text" wire:model="new.name" class="wx-input" placeholder="quotation_ready" autocomplete="off">
                    </x-admin.field>
                    <x-admin.field label="Language" for="new.language">
                        <select id="new.language" wire:model="new.language" class="wx-input">
                            @foreach (['en' => 'English', 'en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'ur' => 'Urdu', 'ar' => 'Arabic'] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field label="Category" for="new.category">
                        <select id="new.category" wire:model="new.category" class="wx-input">
                            <option value="UTILITY">Utility</option>
                            <option value="MARKETING">Marketing</option>
                        </select>
                    </x-admin.field>
                </div>

                <x-admin.field label="Message" for="new.body" hint="Write @{{1}}, @{{2}} and so on where the system fills something in.">
                    <textarea id="new.body" wire:model.live="new.body" rows="5" class="wx-input" placeholder="Salam @{{1}}, your quotation from Woodex Interior is ready: @{{2}}"></textarea>
                </x-admin.field>

                @if (($new['examples'] ?? []) !== [])
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach ($new['examples'] as $index => $example)
                            <x-admin.field label="Example for variable {{ $index + 1 }}" for="new.examples.{{ $index }}">
                                <input id="new.examples.{{ $index }}" type="text" wire:model="new.examples.{{ $index }}" class="wx-input" placeholder="{{ $index === 0 ? 'Ali' : 'https://…' }}">
                            </x-admin.field>
                        @endforeach
                    </div>
                    <p class="text-theme-xs text-gray-500">Meta needs to see what each variable will hold before it will review the template. These examples are not sent to anyone.</p>
                @endif
            </div>

            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showNew', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="send" wire:loading.attr="disabled">Send to Meta</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

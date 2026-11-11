<div>
    <x-admin.page-header title="Audit log" description="Who changed what, when — model changes, webhook deliveries, integration calls."
                         :breadcrumbs="['System' => null, 'Audit log' => null]" />

    <x-admin.card>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->tabs() as $option)
                    <button type="button" wire:click="$set('tab', '{{ $option['value'] }}')"
                            class="rounded-lg px-3 py-1.5 text-theme-sm font-medium {{ $tab === $option['value'] ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' }}">
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($tab === 'changes')
                    <select wire:model.live="causerId" class="wx-input max-w-44">
                        <option value="">Everyone</option>
                        @foreach ($this->causers() as $causer)
                            <option value="{{ $causer['value'] }}">{{ $causer['label'] }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="subjectType" class="wx-input max-w-44">
                        <option value="">Everything</option>
                        @foreach ($this->subjectTypes() as $type)
                            <option value="{{ $type['value'] }}">{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                @endif
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search…" class="wx-input max-w-56" />
            </div>
        </div>
    </x-admin.card>

    @if ($tab === 'changes')
        <x-admin.card :title="'Model changes ('.$this->changes()->total().')'" :padding="false" class="mt-6">
            @forelse ($this->changes() as $activity)
                <div wire:key="act-{{ $activity->id }}" class="border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <span class="font-medium text-gray-800 dark:text-white/90">{{ $activity->description }}</span>
                            <span class="text-theme-sm text-gray-500">
                                · {{ $activity->subject_type !== null ? \Illuminate\Support\Str::headline(class_basename($activity->subject_type)).' #'.$activity->subject_id : '—' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-theme-xs text-gray-500">
                            @if ($activity->causer)
                                <span class="font-medium">{{ $activity->causer->name }}</span>
                            @else
                                <span>system</span>
                            @endif
                            <span>· {{ $activity->created_at?->timezone('Asia/Karachi')->format('d M, g:i a') }}</span>
                            @if ($activity->event)
                                <x-admin.badge color="gray">{{ $activity->event }}</x-admin.badge>
                            @endif
                        </div>
                    </div>
                    @if ($activity->attribute_changes !== null && $activity->attribute_changes !== [])
                        <details class="mt-1 text-theme-sm">
                            <summary class="cursor-pointer text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">what changed</summary>
                            <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-400">
                                @foreach ($activity->attribute_changes as $field => $change)
                                    <li>
                                        <span class="font-medium">{{ \Illuminate\Support\Str::headline((string) $field) }}:</span>
                                        {{ \Illuminate\Support\Js::from($change) }}
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @empty
                <x-admin.empty icon="archive" title="No changes match" description="Activity on leads, contacts, quotations, invoices and payments appears here as it happens." />
            @endforelse
            <div class="px-5 py-3">{{ $this->changes()->links() }}</div>
        </x-admin.card>
    @elseif ($tab === 'webhooks')
        <x-admin.card :title="'Webhook deliveries ('.$this->webhooks()->total().')'" :padding="false" class="mt-6">
            @forelse ($this->webhooks() as $event)
                <div wire:key="hook-{{ $event->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <div>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ ucfirst($event->provider) }} · {{ $event->type ?? 'update' }}</span>
                        <span class="block text-theme-xs text-gray-500">update {{ $event->external_id }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-admin.badge :color="$event->signature_valid ? 'success' : 'error'">{{ $event->signature_valid ? 'signed' : 'unsigned' }}</x-admin.badge>
                        <x-admin.badge :color="$event->status->value === 'processed' ? 'success' : ($event->status->value === 'failed' ? 'error' : 'gray')">{{ $event->status->label() }}</x-admin.badge>
                        <span class="text-theme-xs text-gray-500">{{ $event->created_at?->timezone('Asia/Karachi')->format('d M, g:i a') }}</span>
                    </div>
                </div>
            @empty
                <x-admin.empty icon="link" title="No webhook deliveries yet" description="Every incoming Telegram update lands here, signed or not." />
            @endforelse
            <div class="px-5 py-3">{{ $this->webhooks()->links() }}</div>
        </x-admin.card>
    @else
        <x-admin.card :title="'Integration calls ('.$this->integrations()->total().')'" :padding="false" class="mt-6">
            @forelse ($this->integrations() as $log)
                <div wire:key="int-{{ $log->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <div>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Str::headline($log->integration) }} · {{ $log->action }}</span>
                        @if ($log->error)
                            <span class="block text-theme-xs text-error-600">{{ $log->error }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <x-admin.badge :color="$log->ok ? 'success' : 'error'">{{ $log->ok ? 'ok' : 'failed' }}</x-admin.badge>
                        @if ($log->status_code)
                            <span class="text-theme-xs text-gray-500">HTTP {{ $log->status_code }} · {{ $log->duration_ms }} ms</span>
                        @endif
                        <span class="text-theme-xs text-gray-500">{{ $log->created_at?->diffForHumans() }}</span>
                    </div>
                </div>
            @empty
                <x-admin.empty icon="link" title="No integration calls yet" description="Outbound Telegram and other API calls are logged here (kept 30 days)." />
            @endforelse
            <div class="px-5 py-3">{{ $this->integrations()->links() }}</div>
        </x-admin.card>
    @endif
</div>

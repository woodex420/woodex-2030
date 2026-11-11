<div>
    <x-admin.page-header title="Recycle bin" description="Deleted records stay here until restored. Erasing (owner only) anonymises a client's personal data for a privacy request."
                         :breadcrumbs="['System' => null, 'Recycle bin' => null]" />

    <div class="mb-4 inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03]" role="tablist">
        @foreach (['leads' => 'Leads', 'contacts' => 'Contacts', 'companies' => 'Companies', 'quotes' => 'Quotations'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['rounded-md px-3 py-1.5 text-theme-sm font-medium transition', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $tab === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>
                {{ $label }} <span class="opacity-70">({{ $counts[$key] }})</span>
            </button>
        @endforeach
    </div>

    <x-admin.card :padding="false">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800" wire:loading.class="opacity-60">
            @forelse ($records as $record)
                <li wire:key="{{ $tab }}-{{ $record->id }}" class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        @if ($tab === 'leads')
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $record->ref }} · {{ $record->contact?->name ?? 'Unknown contact' }}</p>
                            <p class="text-theme-xs text-gray-500">{{ $record->business_line->label() }} · {{ $record->owner?->name ?? 'Unassigned' }} · deleted {{ $record->deleted_at?->diffForHumans() }}</p>
                        @elseif ($tab === 'contacts')
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $record->name }}</p>
                            <p class="text-theme-xs text-gray-500">{{ \App\Support\Phone::display($record->phone) ?: ($record->email ?: 'no contact details') }} · {{ $record->leads_count }} {{ \Illuminate\Support\Str::plural('enquiry', $record->leads_count) }} · deleted {{ $record->deleted_at?->diffForHumans() }}</p>
                        @elseif ($tab === 'quotes')
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $record->label() }} · {{ $record->contact?->name ?? 'Unknown contact' }}</p>
                            <p class="text-theme-xs text-gray-500">{{ $record->title }} · {{ \App\Support\Money::ofMinor((int) $record->total_minor)->format() }} · {{ $record->owner?->name ?? 'No owner' }} · deleted {{ $record->deleted_at?->diffForHumans() }}</p>
                        @else
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $record->name }}</p>
                            <p class="text-theme-xs text-gray-500">{{ $record->typeLabel() }} · deleted {{ $record->deleted_at?->diffForHumans() }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if (! ($tab === 'contacts' && isset($record->meta['erased_at'])))
                            <x-admin.button variant="secondary" size="sm" icon="rotate-ccw" wire:click="restore('{{ \Illuminate\Support\Str::singular($tab) }}', {{ $record->id }})">Restore</x-admin.button>
                        @endif
                        @if ($tab === 'contacts' && $isOwner && ! isset($record->meta['erased_at']))
                            <x-admin.button variant="danger" size="sm" wire:click="erase({{ $record->id }})"
                                            wire:confirm="Erase {{ $record->name }}'s personal data? Name, phone, email, address, notes and files are removed for good. Totals stay.">Erase data</x-admin.button>
                        @elseif ($tab === 'contacts' && isset($record->meta['erased_at']))
                            <x-admin.badge color="gray">Erased</x-admin.badge>
                        @endif
                    </div>
                </li>
            @empty
                <li><x-admin.empty icon="archive" title="Nothing here" description="Deleted {{ $tab }} appear here and can be restored." /></li>
            @endforelse
        </ul>
        {{ $records->links('components.admin.pagination') }}
    </x-admin.card>
</div>

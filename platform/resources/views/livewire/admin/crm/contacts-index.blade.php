@use('App\Support\Phone')
<div>
    <x-admin.page-header title="Contacts" description="Every client and prospect in one place. Phone numbers are stored once, in a standard format, so duplicates are caught automatically."
                         :breadcrumbs="['CRM' => null, 'Contacts' => null]">
        <x-slot:actions>
            @can('create', \App\Domain\Crm\Models\Contact::class)
                <x-admin.button icon="user-plus" wire:click="openCreate">New contact</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <div class="relative md:col-span-2">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email or phone in any format" class="wx-input pl-10" aria-label="Search contacts">
        </div>
        <select wire:model.live="type" class="wx-input" aria-label="Type">
            <option value="">All contacts</option>
            <option value="clients">Clients</option>
            <option value="prospects">Prospects</option>
            <optgroup label="Type">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </optgroup>
        </select>
        <select wire:model.live="tag" class="wx-input" aria-label="Tag">
            <option value="">All tags</option>
            @foreach ($tags as $t)
                <option value="{{ $t->slug }}">{{ $t->name }}</option>
            @endforeach
        </select>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Phone · Email</th>
                        <th class="px-5 py-3 font-medium">Location</th>
                        <th class="px-5 py-3 font-medium">Enquiries</th>
                        <th class="px-5 py-3 font-medium">Tags</th>
                        <th class="px-5 py-3 font-medium">Last activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($contacts as $contact)
                        <tr wire:key="contact-{{ $contact->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.contacts.show', $contact) }}" wire:navigate class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-sand-100 text-theme-xs font-semibold text-navy-900 dark:bg-white/10 dark:text-white">{{ \Illuminate\Support\Str::of($contact->name)->explode(' ')->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('') }}</span>
                                    <span>
                                        <span class="block font-medium text-gray-800 hover:underline dark:text-white/90">{{ $contact->name }}</span>
                                        <span class="flex items-center gap-1.5 text-theme-xs text-gray-500">
                                            {{ $contact->company?->name ?? $contact->client_type->label() }}
                                            @if ($contact->is_client)<x-admin.badge color="success" :dot="false" class="!px-1.5 !py-0">Client</x-admin.badge>@endif
                                        </span>
                                    </span>
                                </a>
                            </td>
                            <td class="px-5 py-3">
                                <span class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    {{ Phone::display($contact->phone) ?: '—' }}
                                    @if ($wa = Phone::whatsappUrl($contact->whatsappNumber()))
                                        <a href="{{ $wa }}" target="_blank" rel="noopener" class="text-success-600 hover:text-success-700" title="WhatsApp"><x-icon name="whatsapp" class="size-3.5" /></a>
                                    @endif
                                    @if ($contact->phone)
                                        <a href="{{ Phone::telUrl($contact->phone) }}" class="text-navy-600 hover:text-navy-800 dark:text-gray-300" title="Call"><x-icon name="phone" class="size-3.5" /></a>
                                    @endif
                                </span>
                                <span class="block truncate text-theme-xs text-gray-500">{{ $contact->email }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ trim(($contact->area ?? '').', '.($contact->city ?? ''), ', ') ?: '—' }}</td>
                            <td class="px-5 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                {{ $contact->leads_count }}
                                @if ($contact->open_leads_count > 0)
                                    <span class="text-theme-xs text-info-600">({{ $contact->open_leads_count }} open)</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($contact->tags->take(3) as $t)
                                        <x-admin.badge :dot="false">{{ $t->name }}</x-admin.badge>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap text-theme-xs text-gray-500">{{ $contact->last_activity_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                @if ($hasFilters)
                                    <x-admin.empty icon="filter" title="No contacts match" description="Try another name, number or filter.">
                                        <x-admin.button variant="secondary" wire:click="clearFilters">Clear filters</x-admin.button>
                                    </x-admin.empty>
                                @else
                                    <x-admin.empty icon="contact" title="No contacts yet" description="Contacts are created automatically from every lead, or add one by hand." />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $contacts->links('components.admin.pagination') }}
    </x-admin.card>

    @if ($showCreate)
        <x-admin.modal name="showCreate" title="New contact" description="Use this for architects, suppliers and referrers. For a new enquiry, add a lead instead." submit="create">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Name" for="form.name" class="sm:col-span-2">
                    <input id="form.name" type="text" wire:model="form.name" class="wx-input" autofocus>
                </x-admin.field>
                <x-admin.field label="Phone / WhatsApp" for="form.phone">
                    <input id="form.phone" type="tel" wire:model="form.phone" class="wx-input" placeholder="0322 4000768">
                </x-admin.field>
                <x-admin.field label="Email" for="form.email">
                    <input id="form.email" type="email" wire:model="form.email" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Area / society" for="form.area">
                    <input id="form.area" type="text" wire:model="form.area" class="wx-input" placeholder="DHA Phase 6">
                </x-admin.field>
                <x-admin.field label="City" for="form.city">
                    <input id="form.city" type="text" wire:model="form.city" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Type" for="form.client_type">
                    <select id="form.client_type" wire:model="form.client_type" class="wx-input">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <label class="flex items-center gap-2 self-end pb-2 text-theme-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="form.consent_whatsapp" class="rounded border-gray-300"> OK to message on WhatsApp
                </label>
            </div>
            @if ($duplicateId)
                <p class="mt-4 rounded-lg bg-warning-50 p-3 text-theme-sm text-warning-700">
                    This person is already saved. <a href="{{ route('admin.contacts.show', $duplicateId) }}" wire:navigate class="font-medium underline">Open the existing contact</a>
                </p>
            @endif
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showCreate', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Save contact</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

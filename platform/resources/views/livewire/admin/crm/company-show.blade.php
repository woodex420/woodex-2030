@php($canEdit = auth()->user()->can('update', $company))
<div>
    <x-admin.page-header :title="$company->name" :description="$company->typeLabel().($company->city ? ' · '.$company->city : '')"
                         :breadcrumbs="['CRM' => null, 'Companies' => route('admin.contacts.index', ['tab' => 'companies']), $company->name => null]">
        <x-slot:actions>
            @if ($canEdit)
                <x-admin.button variant="secondary" icon="pencil" wire:click="openEdit">Edit</x-admin.button>
            @endif
            @can('delete', $company)
                <x-admin.button variant="secondary" icon="trash" wire:click="$set('showDelete', true)">Delete</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6">
            <x-admin.card title="Details">
                <dl class="space-y-3 text-theme-sm">
                    <div>
                        <dt class="text-theme-xs text-gray-500">Phone</dt>
                        <dd class="flex items-center gap-2 text-gray-800 dark:text-white/90">
                            {{ \App\Support\Phone::display($company->phone) ?: '—' }}
                            @if ($company->phone)
                                <a href="{{ \App\Support\Phone::telUrl($company->phone) }}" class="text-navy-600 hover:text-navy-800 dark:text-gray-300" title="Call"><x-icon name="phone" class="size-4" /></a>
                                @if ($wa = \App\Support\Phone::whatsappUrl($company->phone))
                                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="text-success-600" title="WhatsApp"><x-icon name="whatsapp" class="size-4" /></a>
                                @endif
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-theme-xs text-gray-500">Email</dt><dd class="text-gray-800 dark:text-white/90">{{ $company->email ?: '—' }}</dd></div>
                    <div>
                        <dt class="text-theme-xs text-gray-500">Website</dt>
                        <dd>@if ($company->website)<a href="{{ $company->website }}" target="_blank" rel="noopener" class="text-navy-700 hover:underline dark:text-white/90">{{ preg_replace('#^https?://#', '', $company->website) }}</a>@else — @endif</dd>
                    </div>
                    <div><dt class="text-theme-xs text-gray-500">Address</dt><dd class="text-gray-800 dark:text-white/90">{{ collect([$company->address, $company->city])->filter()->implode(', ') ?: '—' }}</dd></div>
                    <div><dt class="text-theme-xs text-gray-500">NTN</dt><dd class="text-gray-800 dark:text-white/90">{{ $company->ntn ?: '—' }}</dd></div>
                    <div><dt class="text-theme-xs text-gray-500">Added by</dt><dd class="text-gray-800 dark:text-white/90">{{ $company->owner?->name ?? '—' }} · {{ $company->created_at?->format('j M Y') }}</dd></div>
                    @if ($company->notes)
                        <div><dt class="text-theme-xs text-gray-500">Notes</dt><dd class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $company->notes }}</dd></div>
                    @endif
                </dl>
            </x-admin.card>
        </div>

        <div class="space-y-6 xl:col-span-2">
            <x-admin.card title="People ({{ $contacts->count() }})">
                @if ($canEdit)
                    <div class="relative mb-4">
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                        <input type="search" wire:model.live.debounce.300ms="contactSearch" class="wx-input pl-10" placeholder="Link a contact: type a name or phone…" aria-label="Find a contact to link">
                        @if ($candidates->isNotEmpty())
                            <ul class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                                @foreach ($candidates as $candidate)
                                    <li>
                                        <button type="button" wire:click="linkContact({{ $candidate->id }})" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-theme-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                            <span>{{ $candidate->name }} <span class="text-theme-xs text-gray-500">{{ \App\Support\Phone::display($candidate->phone) }}</span></span>
                                            <span class="text-theme-xs text-navy-600">Link</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($contacts as $contact)
                        <li wire:key="person-{{ $contact->id }}" class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.contacts.show', $contact) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $contact->name }}</a>
                                <p class="text-theme-xs text-gray-500">{{ \App\Support\Phone::display($contact->phone) ?: $contact->email }} · {{ $contact->leads_count }} {{ \Illuminate\Support\Str::plural('lead', $contact->leads_count) }}</p>
                            </div>
                            @if ($canEdit)
                                <button type="button" wire:click="unlinkContact({{ $contact->id }})" wire:confirm="Remove {{ $contact->name }} from this company?" class="text-theme-xs text-gray-400 hover:text-error-600">Remove</button>
                            @endif
                        </li>
                    @empty
                        <li class="py-6 text-center text-theme-sm text-gray-500">No people linked yet.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            <x-admin.card title="Enquiries ({{ $leads->count() }})" :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($leads as $lead)
                        <li wire:key="company-lead-{{ $lead->id }}" class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $lead->ref }} · {{ $lead->contact->name }}</a>
                                <p class="text-theme-xs text-gray-500">{{ $lead->business_line->label() }}{{ $lead->service ? ' · '.\Illuminate\Support\Str::headline($lead->service) : '' }} · {{ $lead->owner?->name ?? 'Unassigned' }}</p>
                            </div>
                            <x-admin.badge :color="$lead->stage->badgeColor()">{{ $lead->stage->name }}</x-admin.badge>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-theme-sm text-gray-500">No enquiries from this company yet.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            @can('viewAny', \App\Domain\Sales\Models\Quote::class)
                <x-admin.card title="Quotations" description="Quotations for the company and its people. Invoices join in P3.">
                    <x-admin.quote-list :quotes="$quotes" :lakh="$lakh" empty="No quotations for this company yet." />
                </x-admin.card>
            @endcan
        </div>
    </div>

    @if ($showEdit)
        <x-admin.modal name="showEdit" title="Edit company" submit="save" width="max-w-2xl">
            <x-admin.company-fields />
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showEdit', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="check">Save</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($showDelete)
        <x-admin.modal name="showDelete" title="Delete {{ $company->name }}?" description="The company goes to the recycle bin and can be restored. People and enquiries are kept.">
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showDelete', false)">Cancel</x-admin.button>
                <x-admin.button variant="danger" icon="trash" wire:click="delete">Delete</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

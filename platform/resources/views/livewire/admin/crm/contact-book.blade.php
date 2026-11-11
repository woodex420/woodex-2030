@use('App\Support\Money')
<div>
    <x-admin.page-header :title="$heading" :description="$blurb"
                         :breadcrumbs="['CRM' => null, 'Contacts' => null, $heading => null]" />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <label class="wx-label" for="segment-search">Search</label>
            <input id="segment-search" type="search" wire:model.live.debounce.300ms="search" class="wx-input" placeholder="Name, phone or email" />
        </div>
        <div class="ml-auto flex gap-2 pb-2 text-theme-xs">
            <a href="{{ route('admin.contacts.index', ['tab' => 'people']) }}" class="rounded-lg border border-gray-300 px-3 py-2 font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">People</a>
            <a href="{{ route('admin.contacts.index', ['tab' => 'clients']) }}" class="rounded-lg border border-gray-300 px-3 py-2 font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Clients</a>
            <a href="{{ route('admin.contacts.index', ['tab' => 'customers']) }}" class="rounded-lg border border-gray-300 px-3 py-2 font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Customers</a>
            <a href="{{ route('admin.contacts.index', ['tab' => 'visitors']) }}" class="rounded-lg border border-gray-300 px-3 py-2 font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Visitors</a>
        </div>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3">Name</th>
                        <th class="hidden px-5 py-3 sm:table-cell">Contact</th>
                        <th class="hidden px-5 py-3 md:table-cell">Owner</th>
                        @if ($mode === 'customers')
                            <th class="px-5 py-3 text-right">Won quotes</th>
                            <th class="px-5 py-3 text-right">Won value</th>
                            <th class="px-5 py-3 text-right">Projects</th>
                        @else
                            <th class="px-5 py-3 text-right">Enquiries</th>
                            <th class="px-5 py-3 text-right">Still open</th>
                            <th class="hidden px-5 py-3 md:table-cell">Last enquiry</th>
                        @endif
                        <th class="hidden px-5 py-3 lg:table-cell">Last activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($contacts as $contact)
                        <tr wire:key="{{ $mode }}-{{ $contact->id }}">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.contacts.show', $contact) }}" class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $contact->name }}</a>
                                @if ($contact->company)
                                    <span class="block text-theme-xs text-gray-500">{{ $contact->company->name }}</span>
                                @endif
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 sm:table-cell dark:text-gray-400">
                                {{ $contact->phone ?: '—' }}
                                @if ($contact->email)<span class="block text-theme-xs text-gray-400">{{ $contact->email }}</span>@endif
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">{{ $contact->owner?->name ?? '—' }}</td>
                            @if ($mode === 'customers')
                                <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $contact->won_quotes_count }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) ($contact->won_value_minor ?? 0))->format($lakh) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $contact->projects_count }}</td>
                            @else
                                <td class="px-5 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ $contact->leads_count }}</td>
                                <td class="px-5 py-3 text-right tabular-nums {{ $contact->open_leads_count > 0 ? 'text-warning-700' : 'text-gray-400' }}">{{ $contact->open_leads_count }}</td>
                                <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">
                                    {{ $contact->leads_max_created_at ? \Illuminate\Support\Carbon::parse($contact->leads_max_created_at)->format('j M Y') : '—' }}
                                    @if ($latestEnquiries->get($contact->id))
                                        <span class="block text-theme-xs text-gray-400">{{ $latestEnquiries->get($contact->id)->source->label() }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="hidden px-5 py-3 text-gray-600 lg:table-cell dark:text-gray-400">{{ $contact->last_activity_at?->format('j M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                @if ($mode === 'customers')
                                    No customer yet. A contact becomes one when a quotation is accepted or a project is opened — both happen from the quotation screen.
                                @else
                                    No website enquiry waiting. When the form, the estimator or the chat widget brings someone in, they appear here until they buy or until a quotation is out.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <div class="mt-4">{{ $contacts->links() }}</div>
</div>

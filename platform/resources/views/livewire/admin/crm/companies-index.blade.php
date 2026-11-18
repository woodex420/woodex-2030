<div>
    <x-admin.page-header title="Companies" description="Developers, architects and businesses: their people, enquiries and quotations in one place."
                         :breadcrumbs="['CRM' => null, 'Companies' => null]">
        <x-slot:actions>
            @can('create', \App\Domain\Crm\Models\Company::class)
                <x-admin.button icon="plus" wire:click="openCreate">New company</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <div class="relative md:col-span-2">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, phone, email or NTN" class="wx-input pl-10" aria-label="Search companies">
        </div>
        <select wire:model.live="type" class="wx-input" aria-label="Type">
            <option value="">All types</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="sort" class="wx-input" aria-label="Sort">
            <option value="name">Sort: name A–Z</option>
            <option value="newest">Sort: newest first</option>
            <option value="leads">Sort: most open leads</option>
        </select>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Company</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Contact</th>
                        <th class="hidden px-5 py-3 font-medium lg:table-cell">City</th>
                        <th class="px-5 py-3 text-right font-medium">People</th>
                        <th class="px-5 py-3 text-right font-medium">Open leads</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($companies as $company)
                        <tr wire:key="company-{{ $company->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.companies.show', $company) }}" wire:navigate class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-navy-50 text-theme-xs font-semibold text-navy-700 dark:bg-white/10 dark:text-white">
                                        {{ \Illuminate\Support\Str::of($company->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-gray-800 hover:underline dark:text-white/90">{{ $company->name }}</span>
                                        <span class="block text-theme-xs text-gray-500">{{ $company->typeLabel() }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">
                                {{ \App\Support\Phone::display($company->phone) ?: '—' }}
                                @if ($company->email)<span class="block text-theme-xs text-gray-400">{{ $company->email }}</span>@endif
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 lg:table-cell dark:text-gray-400">{{ $company->city ?: '—' }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ $company->contacts_count }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">
                                @if ($company->open_leads_count > 0)
                                    <x-admin.badge color="navy" :dot="false">{{ $company->open_leads_count }}</x-admin.badge>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-admin.empty icon="building" title="{{ $search !== '' || $type !== '' ? 'No companies match' : 'No companies yet' }}"
                                               description="Add developers, architects and businesses you work with, then link their people from the company page." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $companies->links('components.admin.pagination') }}
    </x-admin.card>

    @if ($showCreate)
        <x-admin.modal name="showCreate" title="New company" submit="create" width="max-w-2xl">
            <x-admin.company-fields />
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showCreate', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="check">Save company</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

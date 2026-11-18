    <x-admin.card title="Estimates">
        <div class="mb-4 flex flex-wrap gap-2">
            <input type="search" wire:model.live.debounce.350ms="search" aria-label="Search estimates" placeholder="Search estimate or client" class="wx-input max-w-xs">
            <select wire:model.live="service" aria-label="Filter by service" class="wx-input max-w-48"><option value="">All services</option>@foreach ($services as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
            <select aria-label="Filter by status" wire:model.live="status" class="wx-input max-w-48">
                <option value="">All statuses</option>
                @foreach ($statuses as $choice)
                    <option value="{{ $choice->value }}">{{ ucfirst($choice->value) }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Estimate</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Service</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Client</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400 text-right">Range</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400 text-right">Total</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Status</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Quote</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($estimates as $estimate)
                        <tr wire:key="estimate-{{ $estimate->id }}">
                            <td class="px-3 py-2.5">
                                <a href="{{ route('admin.estimations.show', $estimate) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $estimate->number }}</a>
                                <p class="text-gray-500 dark:text-gray-400">{{ $estimate->title }}</p>
                                @if ($estimate->lead !== null)
                                    <p class="text-theme-xs text-gray-400">
                                        Enquiry <a href="{{ route('admin.leads.show', $estimate->lead) }}" class="hover:underline">{{ $estimate->lead->ref }}</a>
                                    </p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">{{ $services[$estimate->service_key] ?? $estimate->service_key }}</td>
                            <td class="px-3 py-2.5">{{ $estimate->contact?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right">
                                {{ \App\Support\Money::ofMinor((int) ($estimate->computed['range_low_minor'] ?? 0))->format() }} –
                                {{ \App\Support\Money::ofMinor((int) ($estimate->computed['range_high_minor'] ?? 0))->format() }}
                            </td>
                            <td class="px-3 py-2.5 text-right font-medium">{{ \App\Support\Money::ofMinor((int) ($estimate->computed['total_minor'] ?? 0))->format() }}</td>
                            <td class="px-3 py-2.5">
                                @if ($estimate->status === App\Domain\Sales\Enums\EstimateStatus::Draft)
                                    <x-admin.badge color="warning">Draft</x-admin.badge>
                                @elseif ($estimate->status === App\Domain\Sales\Enums\EstimateStatus::Reviewed)
                                    <x-admin.badge color="info">Reviewed</x-admin.badge>
                                @else
                                    <x-admin.badge color="success">Converted</x-admin.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                @if ($estimate->quote !== null)
                                    <a href="{{ route('admin.quotes.show', $estimate->quote) }}" class="text-brand-600 hover:underline dark:text-brand-400">{{ $estimate->quote->number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-10 text-center">
                                <p class="text-gray-500 dark:text-gray-400">No estimates yet — every enquiry carries the service and the size, so an estimate starts from the lead.</p>
                                <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                                    <a href="{{ route('admin.leads.index') }}"><x-admin.button variant="secondary" icon="users">Start from an enquiry</x-admin.button></a>
                                    @can('estimates.manage')
                                        <a href="{{ route('admin.estimations.create') }}"><x-admin.button icon="plus">New estimate</x-admin.button></a>
                                    @endcan
                                </div>
                                <p class="mt-2 text-theme-xs text-gray-400">A phone call with no lead yet? Create the lead first — one timeline, one source of truth.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $estimates->links() }}</div>
    </x-admin.card>

@use('App\Support\Money')
<div>
    <x-admin.page-header title="Proposals"
                         description="Quotations still in negotiation — drafted, waiting for approval, with the client, or back for changes. A proposal leaves this page when the client accepts, declines, or the price lapses."
                         :breadcrumbs="['Sales' => null, 'Quotations' => null, 'Proposals' => null]" />

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Value in play</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($openValue)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Not sent yet</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ $notSent }}</p>
        </div>
        <div class="rounded-xl border px-4 py-3 {{ $awaitingApproval > 0 ? 'border-warning-200 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10' : 'border-gray-200 dark:border-gray-800' }}">
            <p class="text-theme-xs text-gray-500">Awaiting approval</p>
            <p class="text-theme-sm font-medium tabular-nums {{ $awaitingApproval > 0 ? 'text-warning-700' : 'text-gray-800 dark:text-white/90' }}">{{ $awaitingApproval }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Oldest open</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ $oldestOpenDays }} {{ \Illuminate\Support\Str::plural('day', $oldestOpenDays) }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <label class="wx-label" for="proposal-search">Search</label>
            <input id="proposal-search" type="search" wire:model.live.debounce.300ms="search" class="wx-input" placeholder="Number, title or client" />
        </div>
        <a href="{{ route('admin.quotes.index', ['tab' => 'pipeline']) }}" class="ml-auto pb-2 text-theme-sm font-medium text-brand-600 hover:underline">See the pipeline →</a>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3">Quote</th>
                        <th class="px-5 py-3">Client</th>
                        <th class="hidden px-5 py-3 md:table-cell">Owner</th>
                        <th class="hidden px-5 py-3 sm:table-cell">Created</th>
                        <th class="hidden px-5 py-3 lg:table-cell">Valid until</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Value</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($proposals as $quote)
                        <tr wire:key="proposal-{{ $quote->id }}">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.quotes.show', ['quote' => $quote->id]) }}" class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $quote->number }}</a>
                                <span class="block text-theme-xs text-gray-500">{{ $quote->title }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $quote->contact?->name ?? '—' }}</td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">{{ $quote->owner?->name ?? '—' }}</td>
                            <td class="hidden px-5 py-3 whitespace-nowrap text-gray-600 sm:table-cell dark:text-gray-400">{{ $quote->created_at->format('j M Y') }}</td>
                            <td class="hidden px-5 py-3 whitespace-nowrap lg:table-cell {{ $quote->valid_until && $quote->valid_until->isPast() ? 'text-error-600' : 'text-gray-600 dark:text-gray-400' }}">
                                {{ $quote->valid_until?->format('j M Y') ?? '—' }}
                            </td>
                            <td class="px-5 py-3"><x-admin.badge :color="$quote->status->color()">{{ $quote->status->label() }}</x-admin.badge></td>
                            <td class="px-5 py-3 text-right tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $quote->total_minor)->format($lakh) }}</td>
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('admin.quotes.show', ['quote' => $quote->id]) }}" class="text-theme-xs font-medium text-brand-600 hover:underline">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center text-theme-sm text-gray-500">
                                No proposal is in negotiation. Every quotation is either won, lost, or not started yet — open Quotations to begin one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <div class="mt-4">{{ $proposals->links() }}</div>
</div>

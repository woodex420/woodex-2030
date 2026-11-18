@use('App\Support\Money')
<div>
    <x-admin.page-header title="Sales pipeline"
                         description="Every quotation in the stage the OS records for it. The forecast is value × stage probability over these records — a calculation you can check, not a number typed into a dashboard."
                         :breadcrumbs="['Sales' => null, 'Quotations' => null, 'Pipeline' => null]" />

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Open value</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($openValue)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Weighted forecast</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($forecast)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Won (accepted quotes)</p>
            <p class="text-theme-sm font-medium tabular-nums text-success-600">{{ Money::ofMinor($wonValue)->format($lakh) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-theme-xs text-gray-500">Quotations in the pipeline</p>
            <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ $totalQuotes }}</p>
        </div>
    </div>

    @if ($totalQuotes === 0)
        <x-admin.card>
            <p class="text-theme-sm text-gray-500">
                No quotation yet, so the pipeline is honestly empty.
                <a href="{{ route('admin.quotes.index') }}" class="font-medium text-brand-600 hover:underline">Open Quotations</a>
                to start one from an enquiry.
            </p>
        </x-admin.card>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($columns as $key => $column)
                @continue ($key === 'closed' && $column['quotes'] === [])
                <div class="rounded-xl border border-gray-200 bg-gray-50/50 dark:border-gray-800 dark:bg-white/[0.02]">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $column['label'] }}</p>
                            <span class="rounded-full bg-white px-2 py-0.5 text-theme-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ count($column['quotes']) }}</span>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500">
                            {{ Money::ofMinor((int) $column['value'])->format($lakh) }}
                            @if (! in_array($key, ['accepted', 'closed'], true))
                                · weighted {{ Money::ofMinor((int) $column['weighted'])->format($lakh) }}
                            @endif
                        </p>
                    </div>

                    <div class="space-y-2 p-3">
                        @forelse ($column['quotes'] as $quote)
                            <a href="{{ route('admin.quotes.show', ['quote' => $quote->id]) }}"
                               wire:key="pipe-{{ $key }}-{{ $quote->id }}"
                               class="block rounded-lg border border-gray-200 bg-white px-3 py-2 hover:border-brand-300 dark:border-gray-700 dark:bg-gray-900">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $quote->number }}</p>
                                <p class="truncate text-theme-xs text-gray-500">{{ $quote->contact?->name ?? '—' }}</p>
                                <p class="mt-1 text-theme-xs tabular-nums text-gray-600 dark:text-gray-300">{{ Money::ofMinor((int) $quote->total_minor)->format($lakh) }}</p>
                                <p class="text-theme-xs text-gray-400">
                                    {{ $quote->created_at->diffForHumans(short: true) }} old
                                    @if ($quote->owner) · {{ $quote->owner->name }} @endif
                                </p>
                            </a>
                        @empty
                            <p class="px-1 py-2 text-theme-xs text-gray-400">Nothing here.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        <x-admin.card title="How the forecast is weighted" class="mt-5">
            <div class="flex flex-wrap gap-2 text-theme-xs">
                @foreach (['draft' => 'Draft', 'pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'sent' => 'Sent', 'viewed' => 'Viewed', 'changes_requested' => 'Changes requested'] as $key => $label)
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $label }} · {{ $weights[$key] }}%</span>
                @endforeach
            </div>
            <p class="mt-2 text-theme-xs text-gray-500">Accepted counts at 100% and is shown in Won; declined, expired, superseded and void count at zero. Only open quotations enter the forecast.</p>
        </x-admin.card>
    @endif
</div>

@props(['quotes', 'lakh' => false, 'empty' => 'No quotations yet.'])
{{-- Compact quotation list for the lead, contact and company pages (QUO-016). --}}
@if ($quotes->isEmpty())
    <p class="text-theme-sm text-gray-500">{{ $empty }}</p>
@else
    <ul class="-my-2 divide-y divide-gray-100 dark:divide-gray-800">
        @foreach ($quotes as $quote)
            <li wire:key="quote-row-{{ $quote->id }}">
                <a href="{{ route('admin.quotes.show', $quote) }}" wire:navigate class="flex items-center justify-between gap-3 py-2.5 hover:opacity-80">
                    <span class="min-w-0">
                        <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $quote->label() }}</span>
                        <span class="block truncate text-theme-xs text-gray-500">{{ $quote->title }} · {{ $quote->updated_at?->diffForHumans() }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ \App\Support\Money::ofMinor((int) $quote->total_minor)->format($lakh) }}</span>
                        <x-admin.badge :color="$quote->status->color()">{{ $quote->status->label() }}</x-admin.badge>
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
@endif

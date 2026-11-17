<div>
    <x-admin.page-header title="Quote tracking" description="The quotation pipeline the way the auto-chaser sees it: event-driven, no invented states. Follow-up due means the follow-up period passed with fewer than three unanswered chases; stale means three chases — an answer in itself."
                         :breadcrumbs="['Sales' => null, 'Quote tracking' => null]">
    </x-admin.page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($groups as $key => $summary)
            <button wire:click="showGroup('{{ $key }}')" wire:key="group-{{ $key }}"
                    class="rounded-xl border p-3 text-left transition {{ $key === $group ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30' : 'border-gray-200 hover:border-brand-300 dark:border-gray-800' }}">
                <p class="text-theme-sm font-medium">{{ $summary['label'] }}</p>
                <p class="text-xl font-semibold">{{ $summary['count'] }}</p>
                @if ($summary['count'] > 0 && $summary['value_minor'] > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $this->money((int) $summary['value_minor']) }}</p>
                @endif
            </button>
        @endforeach
    </div>

    <x-admin.card :title="$groups[$group]['label']" :description="$groups[$group]['hint']">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Quote</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Client</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Owner</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Sent</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Valid until</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400 text-right">Value</th>
                        <th class="px-3 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($active as $quote)
                        <tr wire:key="quote-{{ $quote->id }}">
                            <td class="px-3 py-2.5">
                                <a href="{{ route('admin.quotes.show', $quote) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $quote->label() }}</a>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $quote->title }}</p>
                            </td>
                            <td class="px-3 py-2.5">{{ $quote->contact?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5">{{ $quote->owner?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5">{{ $quote->sent_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                @if ($quote->valid_until !== null && $quote->valid_until->isPast())
                                    <x-admin.badge color="error">{{ $quote->valid_until->format('d M Y') }}</x-admin.badge>
                                @elseif ($quote->valid_until !== null && $quote->valid_until->lessThanOrEqualTo(now()->addDays(7)))
                                    <x-admin.badge color="warning">{{ $quote->valid_until->format('d M Y') }}</x-admin.badge>
                                @else
                                    {{ $quote->valid_until?->format('d M Y') ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right font-medium">{{ $this->money((int) $quote->total_minor) }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                @if ($canManage && in_array($quote->status->value, ['sent', 'viewed']))
                                    <x-admin.button size="sm" variant="secondary" wire:click="startChase({{ $quote->id }})">Log chase</x-admin.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Nothing in this group — that is good news.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    @if ($chaseQuoteId !== 0)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:key="chase-modal">
            <x-admin.card class="w-full max-w-lg" title="Log a follow-up">
                <form wire:submit="logChase" class="space-y-4">
                    <div>
                        <label class="wx-label">What happened on this call or message?</label>
                        <textarea wire:model="chaseNote" class="wx-input" rows="3" maxlength="500" placeholder="e.g. Called — client reviewing with family, will decide by Friday"></textarea>
                        @error('chaseNote')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">This logs on the quote's timeline exactly like the automatic chaser, and resets the follow-up clock.</p>
                    <div class="flex justify-end gap-2">
                        <x-admin.button variant="secondary" type="button" wire:click="$set('chaseQuoteId', 0)">Cancel</x-admin.button>
                        <x-admin.button type="submit">Log chase</x-admin.button>
                    </div>
                </form>
            </x-admin.card>
        </div>
    @endif
</div>

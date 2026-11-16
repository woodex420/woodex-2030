@use('App\Support\Money')
@use('App\Domain\Sales\Services\QuoteApprovalRules')
<div>
    <x-admin.page-header title="Approvals" description="Decide quotation requests and open other permitted review queues. Each decision keeps its original domain safeguards."
                         :breadcrumbs="['Overview' => null, 'Approvals' => null]" />

    @if ($reviews !== [])
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            @foreach ($reviews as $review)<x-admin.card :title="$review['title']"><p class="text-theme-sm text-gray-500">{{ $review['detail'] }}</p><x-admin.button variant="secondary" :href="$review['href']" wire:navigate class="mt-4">Open review queue</x-admin.button></x-admin.card>@endforeach
        </div>
    @endif
    @if ($pending !== null)
    <div class="mb-4 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" aria-label="Search approvals" placeholder="Search client, quote number or title" class="wx-input max-w-sm">
        <select wire:model.live="line" aria-label="Approval business line" class="wx-input w-auto"><option value="">All business lines</option>@foreach (\App\Domain\Crm\Enums\BusinessLine::cases() as $businessLine)<option value="{{ $businessLine->value }}">{{ $businessLine->label() }}</option>@endforeach</select>
    </div>
    <x-admin.card :title="'Waiting ('.$pending->total().')'" :padding="false">
        @forelse ($pending as $approval)
            @php $quote = $approval->approvable; @endphp
            <div wire:key="approval-{{ $approval->id }}" class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 last:border-0 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800">
                <div class="min-w-0">
                    <a href="{{ route('admin.quotes.show', $quote) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $quote->label() }} · {{ $quote->title }}</a>
                    <p class="text-theme-sm text-gray-500">{{ $quote->contact->name }} · {{ Money::ofMinor((int) $quote->total_minor)->format($lakh) }} · requested by {{ $approval->requester?->name ?? '—' }} {{ $approval->created_at?->diffForHumans() }}</p>
                    <ul class="mt-1 text-theme-sm text-warning-700 dark:text-warning-400">
                        @foreach ((array) ($approval->payload['reasons'] ?? []) as $reason)<li>{{ $reason }}</li>@endforeach
                    </ul>
                    @if ($approval->reason)<p class="mt-1 text-theme-sm text-gray-600 italic dark:text-gray-400">“{{ $approval->reason }}”</p>@endif
                </div>
                <div class="flex shrink-0 gap-2">
                    <x-admin.button variant="secondary" :href="route('admin.quotes.show', $quote)" wire:navigate>Open</x-admin.button>
                    <x-admin.button variant="secondary" wire:click="openReject({{ $approval->id }})">Decline</x-admin.button>
                    <x-admin.button icon="check" wire:click="approve({{ $approval->id }})" wire:loading.attr="disabled">Approve</x-admin.button>
                </div>
            </div>
        @empty
            <x-admin.empty icon="check-circle" title="Nothing waiting" description="Quotations that need your approval appear here and in your notifications." />
        @endforelse
    </x-admin.card>

    <div class="mt-4">{{ $pending->links() }}</div>
    @endif

    @if ($recent->isNotEmpty())
        <x-admin.card title="Latest15 decisions in the last30days" :padding="false" class="mt-6">
            @foreach ($recent as $approval)
                @php $quote = $approval->approvable; @endphp
                <div wire:key="decided-{{ $approval->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 text-theme-sm last:border-0 dark:border-gray-800">
                    <span>
                        <a href="{{ route('admin.quotes.show', $quote) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $quote->label() }}</a>
                        <span class="text-gray-500">· {{ $quote->contact->name }} · discount {{ QuoteApprovalRules::percent((int) ($approval->payload['discount_bp'] ?? 0)) }}</span>
                    </span>
                    <span class="flex items-center gap-2 text-gray-500">
                        <x-admin.badge :color="$approval->status->value === 'approved' ? 'success' : 'error'">{{ $approval->status->label() }}</x-admin.badge>
                        {{ $approval->decider?->name }} · {{ $approval->decided_at?->diffForHumans() }}
                    </span>
                </div>
            @endforeach
        </x-admin.card>
    @endif

    @if ($showReject)
        <x-admin.modal name="showReject" title="Decline approval" description="The sales person sees your reason and can change the quotation." submit="reject">
            <label for="reject-reason" class="wx-label">Reason</label>
            <textarea id="reject-reason" wire:model="rejectReason" rows="3" class="wx-input h-auto py-2" placeholder="For example: keep the discount at 5%, or add the ceiling lights back."></textarea>
            @error('rejectReason')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
            <x-slot:footer>
                <x-admin.button variant="secondary" type="button" wire:click="$set('showReject', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Decline</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

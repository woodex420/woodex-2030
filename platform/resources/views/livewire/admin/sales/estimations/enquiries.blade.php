<x-admin.card title="Estimator Enquiries" description="Existing CRM enquiries, restricted to records you can view. Unknown/retired services are never assigned an invented price.">
    <input type="search" wire:model.live.debounce.350ms="search" aria-label="Search estimator enquiries" placeholder="Search enquiry or client" class="wx-input mb-4 max-w-xs">
    @error('enquiry')<p role="alert" class="mb-4 text-error-600">{{ $message }}</p>@enderror
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-theme-sm">
            <thead><tr class="border-b border-gray-200 text-gray-500"><th class="px-3 py-3">Enquiry / Client</th><th class="px-3 py-3">Requested service</th><th class="px-3 py-3">Estimate</th><th class="px-3 py-3">Quotation</th><th class="px-3 py-3">Action</th></tr></thead>
            <tbody>
                @forelse ($enquiries as $lead)
                    @php $detail = $enquiryDetails[$lead->id]; $linked = $enquiryEstimates->get($lead->id); @endphp
                    <tr wire:key="estimator-enquiry-{{ $lead->id }}" class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-3 py-3"><a href="{{ route('admin.leads.show', $lead) }}" class="font-medium underline">{{ $lead->ref }}</a><p>{{ $lead->contact?->name }}</p></td>
                        <td class="px-3 py-3">{{ $detail['rate']?->label ?? $detail['service'] ?: 'Not specified' }}<p class="text-theme-xs text-gray-500">{{ $detail['area'] }} · {{ ucfirst($detail['finish']) }}</p>@if ($detail['estimate'])<p class="text-theme-xs text-gray-500">Visitor saw: {{ $detail['estimate'] }}</p>@endif</td>
                        <td class="px-3 py-3">@if ($linked)<a href="{{ route('admin.estimations.show', $linked) }}" class="underline">{{ $linked->number }}</a><p class="text-theme-xs text-gray-500">{{ ucfirst($linked->status->value) }}</p>@else No estimate yet @endif</td>
                        <td class="px-3 py-3">@if ($linked?->quote)<a href="{{ route('admin.quotes.show', $linked->quote) }}" class="underline">{{ $linked->quote->number }}</a>@else — @endif</td>
                        <td class="px-3 py-3">@if ($linked)<a href="{{ route('admin.estimations.show', $linked) }}" class="underline">Open estimate</a>@elseif ($detail['rate'] && $detail['area'] > 0)@can('estimates.manage')<x-admin.button size="sm" wire:click="openEnquiry({{ $lead->id }})" wire:loading.attr="disabled">Start estimate</x-admin.button>@endcan @else <span class="text-theme-xs text-warning-700">Confirm quantity or unknown/retired service in CRM</span>@endif</td>
                    </tr>
                @empty <tr><td colspan="5" class="p-8 text-center text-gray-500">No estimator enquiries match this view.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $enquiries->links() }}</div>
</x-admin.card>

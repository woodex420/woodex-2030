<div>
    <x-admin.page-header title="Estimations" description="One workflow for service rates, estimator enquiries and staff estimates. Saved snapshots stay separate from today's prices; quotations are always drafts until explicitly sent." :breadcrumbs="['Sales' => null, 'Estimations' => null]">
        <x-slot:actions>@can('estimates.manage')<a href="{{ route('admin.estimations.create') }}"><x-admin.button icon="plus">New estimate</x-admin.button></a>@endcan</x-slot:actions>
    </x-admin.page-header>
    <nav class="mb-6 flex flex-wrap gap-2" aria-label="Estimation hub tabs">
        @foreach (['estimates' => 'Estimates', 'rates' => 'Rates', 'enquiries' => 'Estimator Enquiries'] as $key => $label)
            @if (($key === 'rates' && auth()->user()->can('website.edit')) || ($key !== 'rates' && auth()->user()->can('estimates.view') && ($key !== 'enquiries' || auth()->user()->can('leads.view'))))
                <a href="{{ route('admin.estimations.index', ['tab' => $key]) }}" wire:navigate aria-current="{{ $tab === $key ? 'page' : 'false' }}" class="rounded-lg border px-4 py-2 text-theme-sm {{ $tab === $key ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300' }}">{{ $label }}</a>
            @endif
        @endforeach
    </nav>
    @if ($tab === 'estimates') @include('livewire.admin.sales.estimations.estimates')
    @elseif ($tab === 'rates') @include('livewire.admin.sales.estimations.rates')
    @else @include('livewire.admin.sales.estimations.enquiries') @endif
</div>

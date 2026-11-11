<div wire:poll.60s.visible>
    <x-admin.page-header title="Operations control" description="Current workload across your permitted modules. These are live operating queues, not period revenue or profit." :breadcrumbs="['Overview' => null, 'Operations' => null]" />
    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($sections as $section)
            <x-admin.card :title="$section['title']">
                <p class="min-h-16 text-theme-sm text-gray-600 dark:text-gray-300">{{ $section['detail'] }}</p>
                <a href="{{ $section['href'] }}" wire:navigate class="mt-4 inline-flex items-center gap-2 text-theme-sm font-semibold text-brand-600 dark:text-brand-400"><x-icon :name="$section['icon']" class="size-4" />Open {{ $section['title'] }} workspace →</a>
            </x-admin.card>
        @empty
            <x-admin.empty title="No operating modules available" description="Your role does not grant access to these workspaces." />
        @endforelse
    </div>
    @if ($reviews !== [])
        <x-admin.card title="Other review work" description="Open the original workflow to decide. No generic bulk approval bypasses its rules." class="mt-6">
            @foreach ($reviews as $review)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-4 last:border-0 dark:border-gray-800"><div><h3 class="font-semibold">{{ $review['title'] }}</h3><p class="text-theme-sm text-gray-500">{{ $review['detail'] }}</p></div><x-admin.button variant="secondary" :href="$review['href']" wire:navigate>Review</x-admin.button></div>
            @endforeach
        </x-admin.card>
    @endif
</div>

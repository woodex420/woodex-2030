<div>
    <x-admin.page-header title="Ledger & operations reports" description="Open the original report that owns each definition. Figures are not copied into a competing accounting engine." />
    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($sources as $source)
            <x-admin.card :title="$source['title']"><p class="text-theme-sm text-gray-500">{{ $source['definition'] }}</p><x-admin.button variant="secondary" :href="$source['href']" wire:navigate class="mt-4">Open source report</x-admin.button></x-admin.card>
        @empty
            <x-admin.empty title="No additional reports available" description="Your role does not grant access to these source reports." />
        @endforelse
    </div>
</div>

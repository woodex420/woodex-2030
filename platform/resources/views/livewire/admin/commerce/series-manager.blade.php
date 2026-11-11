<div>
<x-admin.page-header title="Series collections" description="Separate product collections, not category ancestors. Drag or use arrows to organize the storefront menu."><x-slot:actions><x-admin.button variant="secondary" :href="route('admin.commerce.categories')">Categories</x-admin.button></x-slot:actions></x-admin.page-header>
<div x-data="{ dragging: null }" class="space-y-3">
@foreach($collections as $index => $collection)
<x-admin.card :title="$collection->name">
<div class="flex flex-wrap items-center gap-3" @can('commerce.catalog.manage')draggable="true" x-on:dragstart.stop="dragging = {{ $collection->id }}" x-on:dragover.prevent x-on:drop.prevent="if(dragging !== null) $wire.reorder(dragging, {{ $collection->id }}); dragging = null"@endcan>
<span aria-hidden="true" class="cursor-grab">⠿</span><p class="flex-1">{{ $collection->slug }} · {{ count(array_filter($references, fn($p) => ($p['series'] ?? null) === $collection->slug)) }} repository product references</p>
@can('commerce.catalog.manage')
@if($index > 0)<x-admin.button variant="secondary" wire:click="reorder({{ $collection->id }}, {{ $collections[$index-1]->id }})" aria-label="Move {{ $collection->name }} up">↑</x-admin.button>@endif
@if($index < count($collections)-1)<x-admin.button variant="secondary" wire:click="reorder({{ $collection->id }}, {{ $collections[$index+1]->id }})" aria-label="Move {{ $collection->name }} down">↓</x-admin.button>@endif
@endcan
</div><p class="mt-2 text-sm text-gray-500">Product assignments remain the existing catalogue's Series keys. Native product assignment and collection-content editing are not enabled by this organizer.</p>
</x-admin.card>
@endforeach
</div>
</div>

<div>
<x-admin.page-header title="Series collections" description="Separate product collections, not category ancestors. Drag or use arrows to organize the storefront menu."><x-slot:actions>@can('commerce.catalog.manage')<x-admin.button wire:click="openCreate">New collection</x-admin.button>@endcan <x-admin.button variant="secondary" :href="route('admin.commerce.categories')">Categories</x-admin.button></x-slot:actions></x-admin.page-header>
<div x-data="{ dragging: null }" class="space-y-3">
@foreach($collections as $index => $collection)
<x-admin.card :title="$collection->name">
<div class="flex flex-wrap items-center gap-3" @can('commerce.catalog.manage')draggable="true" x-on:dragstart.stop="dragging = {{ $collection->id }}" x-on:dragover.prevent x-on:drop.prevent="if(dragging !== null) $wire.reorder(dragging, {{ $collection->id }}); dragging = null"@endcan>
<span aria-hidden="true" class="cursor-grab">⠿</span><p class="flex-1">{{ $collection->slug }} · {{ count(array_filter($references, fn($p) => ($p['series'] ?? null) === $collection->slug)) }} repository product references</p>
@if($collection->published_revision_id)<x-admin.button variant="secondary" :href="route('admin.commerce.series-content.preview', $collection->published_revision_id)">View native publication</x-admin.button>@endif
@can('commerce.catalog.manage')
<x-admin.button variant="secondary" :href="route('admin.commerce.series.edit', $collection)">Edit collection draft</x-admin.button>
@if($index > 0)<x-admin.button variant="secondary" wire:click="reorder({{ $collection->id }}, {{ $collections[$index-1]->id }})" aria-label="Move {{ $collection->name }} up">↑</x-admin.button>@endif
@if($index < count($collections)-1)<x-admin.button variant="secondary" wire:click="reorder({{ $collection->id }}, {{ $collections[$index+1]->id }})" aria-label="Move {{ $collection->name }} down">↓</x-admin.button>@endif
@endcan
</div><p class="mt-2 text-sm text-gray-500">Product assignments remain the existing catalogue's Series keys. Manual native collection drafts are available through Edit collection draft. Automatic published-product rules are available in collection drafts. Hero media and full Shop workflows remain Soon. Native collection membership does not rewrite product classifications or the React storefront.</p>
</x-admin.card>
@endforeach
</div>
@if($showCreate)
<x-admin.modal name="showCreate" title="New Series collection" submit="createCollection">
<div class="space-y-4">
<x-admin.field label="Collection name" for="new-series-name"><input id="new-series-name" wire:model="identity.name" maxlength="120" class="wx-input"></x-admin.field>
<x-admin.field label="Unique slug (lowercase words and hyphens)" for="new-series-slug"><input id="new-series-slug" wire:model="identity.slug" maxlength="140" class="wx-input"></x-admin.field>
@foreach($errors->all() as $message)<p role="alert" class="text-red-600">{{ $message }}</p>@endforeach
<p class="text-sm">Creates an inactive native collection identity and its first draft. The identity is visible to catalogue staff, not activated in connected storefront navigation. No products, photos, publication or existing Series keys are copied.</p>
</div>
<x-slot:footer><x-admin.button variant="secondary" wire:click="$set('showCreate', false)">Cancel</x-admin.button><x-admin.button type="submit" wire:loading.attr="disabled">Create draft and edit</x-admin.button></x-slot:footer>
</x-admin.modal>
@endif
</div>

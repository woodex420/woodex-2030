<ul class="space-y-2 {{ $depth ? 'ml-4 border-l pl-3' : '' }}">
@foreach($nodes as $index => $node)
<li wire:key="tree-{{ $node->id }}">
    <div class="flex flex-wrap items-center gap-2 rounded-lg border bg-white p-3 dark:bg-gray-900" @can('commerce.catalog.manage')draggable="true" x-on:dragstart.stop="dragging = {{ $node->id }}; $event.dataTransfer.effectAllowed = 'move'" x-on:dragover.prevent.stop x-on:drop.prevent.stop="if (dragging !== null && dragging !== {{ $node->id }}) $wire.reorder(dragging, {{ $node->id }}); dragging = null" x-on:dragend="dragging = null"@endcan>
        <span aria-hidden="true" class="cursor-grab text-gray-500">⠿</span>
        <div class="min-w-0 flex-1"><p class="font-semibold">{{ $node->name }}</p><p class="text-xs text-gray-500">{{ $depth === 0 ? 'Main category' : ($depth === 1 ? 'Child / group' : 'Subcategory') }} · {{ $node->is_active ? 'Active' : 'Inactive' }}</p></div>
        @can('commerce.catalog.manage')
        @if($index > 0)<x-admin.button variant="secondary" wire:click="reorder({{ $node->id }}, {{ $nodes[$index - 1]->id }})" aria-label="Move {{ $node->name }} up">↑</x-admin.button>@endif
        @if($index < count($nodes) - 1)<x-admin.button variant="secondary" wire:click="reorder({{ $node->id }}, {{ $nodes[$index + 1]->id }})" aria-label="Move {{ $node->name }} down">↓</x-admin.button>@endif
        <x-admin.button variant="secondary" wire:click="edit({{ $node->id }})" aria-label="Quick edit {{ $node->name }}" title="Quick edit">✎</x-admin.button>
        <x-admin.button variant="secondary" :href="route('admin.commerce.categories.edit', $node)">Edit</x-admin.button>
        @endcan
    </div>
    @if($depth < 20 && $parents->where('parent_id', $node->id)->count())
        @include('livewire.admin.commerce.category-tree', ['nodes' => $parents->where('parent_id', $node->id)->values(), 'depth' => $depth + 1])
    @endif
</li>
@endforeach
</ul>

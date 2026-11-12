<ul class="space-y-2 {{ $depth ? 'ml-4 border-l pl-3' : '' }}">
@foreach($nodes as $node)
@if(in_array($node->id, $visibleCategoryIds))
<li wire:key="category-choice-{{ $node->id }}"><label class="flex items-start gap-2 py-1"><input type="checkbox" wire:model.live="form.category_ids" value="{{ $node->id }}"><span class="{{ $depth === 0 ? 'font-semibold' : 'text-sm' }}">{{ $node->name }} @if(!$node->is_active)<span class="text-xs text-gray-500">Inactive</span>@endif</span></label>
@if($depth < 20 && $categories->where('parent_id', $node->id)->count())@include('livewire.admin.commerce.product-category-tree', ['nodes' => $categories->where('parent_id', $node->id), 'depth' => $depth + 1])@endif
</li>
@endif
@endforeach
</ul>

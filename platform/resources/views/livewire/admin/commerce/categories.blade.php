<div>
    <x-admin.page-header title="Categories" description="Customize the native category tree. Changes are saved here, not published to the existing storefront.">
        <x-slot:actions>@can('commerce.catalog.manage')<x-admin.button wire:click="create">New category</x-admin.button>@endcan</x-slot:actions>
    </x-admin.page-header>
    <div class="mb-5 grid gap-4 lg:grid-cols-2">
        @foreach($parents->whereNull('parent_id') as $segment)
            <x-admin.card :title="$segment->name" description="Native catalogue hierarchy. Categories without products remain valid; no products are invented.">
                @foreach($parents->where('parent_id', $segment->id) as $group)
                    <h3 class="mt-3 font-semibold">{{ $group->name }}</h3>
                    <ul class="mt-1 space-y-1 text-sm">
                        @foreach($parents->where('parent_id', $group->id) as $child)
                            <li>{{ $child->name }} @if(!$child->is_active)<span class="text-gray-500">(inactive)</span>@endif</li>
                        @endforeach
                    </ul>
                @endforeach
            </x-admin.card>
        @endforeach
    </div>
    <p class="mb-4 text-sm text-gray-600">Office Furniture and Home Furniture are parent categories; choose a group or leaf below them. New parent trees are supported. Series (EK, Infinity, Woodex, Cubicle and Nova) are separate product collections, not category ancestors. Collection management is part of the upcoming Products work.</p>
    <x-admin.card>
        <label class="wx-label" for="category-search">Search categories</label>
        <input id="category-search" type="search" wire:model.live.debounce.300ms="search" class="wx-input mb-4" placeholder="Search by category name">
        <p wire:loading class="text-sm" role="status">Updating categories…</p>
        <div class="space-y-3">
            @forelse($categories as $category)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800" wire:key="category-{{ $category->id }}">
                    <div class="min-w-0 break-words">
                        <p class="font-semibold">{{ $category->name }} <x-admin.badge :color="$category->is_active ? 'success' : 'gray'">{{ $category->is_active ? 'Active' : 'Draft / inactive' }}</x-admin.badge></p>
                        <p class="text-sm text-gray-500">{{ $category->slug }} · Position {{ $category->position }} · Parent: {{ $parents->firstWhere('id', $category->parent_id)?->name ?? 'Top level' }}</p>
                        @if($category->description)<p class="mt-2 text-sm whitespace-pre-line">{{ $category->description }}</p>@endif
                    </div>
                    @can('commerce.catalog.manage')<div class="ml-auto flex shrink-0 items-center gap-2"><x-admin.button variant="secondary" wire:click="edit({{ $category->id }})" aria-label="Quick edit {{ $category->name }}" title="Quick edit name, parent and status"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m16 3 5 5-13 13H3v-5Z"/><path d="m14 5 5 5"/></svg></x-admin.button><x-admin.button variant="secondary" :href="route('admin.commerce.categories.edit', $category)">Edit</x-admin.button></div>@endcan
                </div>
            @empty
                <p class="py-8 text-center text-gray-500">No categories found. Create a category or change your search. The default Office/Home hierarchy is installed; product imports remain separate.</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $categories->links() }}</div>
    </x-admin.card>
    @if($showForm)
        <x-admin.modal name="showForm" :title="$editingId ? 'Quick edit category' : 'New category · Step 1 of 2'" submit="save">
            <div class="space-y-4">
                <x-admin.field label="Name" for="form.name"><input id="form.name" wire:model="form.name" class="wx-input" maxlength="120"></x-admin.field>
                <x-admin.field label="Slug (lowercase words separated by hyphens)" for="form.slug"><input id="form.slug" wire:model="form.slug" class="wx-input" maxlength="140"></x-admin.field>
                <x-admin.field label="Parent category" for="form.parent_id"><select id="form.parent_id" wire:model="form.parent_id" class="wx-input"><option value="">Top level</option>@foreach($parents as $parent)@if($parent->id !== $editingId)<option value="{{ $parent->id }}">{{ $parent->name }}</option>@endif@endforeach</select></x-admin.field>
                <x-admin.field label="Description" for="form.description"><textarea id="form.description" wire:model="form.description" class="wx-input" rows="3" maxlength="3000"></textarea></x-admin.field>
                <x-admin.field label="Sort position" for="form.position"><input id="form.position" wire:model="form.position" type="number" min="0" max="100000" class="wx-input"></x-admin.field>
                <label class="flex gap-2"><input type="checkbox" wire:model="form.is_active"> Active in the native catalogue (does not publish to the storefront)</label>
                <p class="text-sm text-gray-500">Deactivate instead of deleting to preserve future product links. Activity changes are audited. @if(!$editingId)Save and continue to images, formatted content, FAQs and featured products.@else Use Edit on the category row for images, content, FAQs and featured products.@endif</p>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save category' : 'Create and continue to editor' }}</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

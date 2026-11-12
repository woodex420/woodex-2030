<div>
    <x-admin.page-header title="Categories" description="Customize the native category tree. Organize the storefront hierarchy and edit category page content.">
        <x-slot:actions>@can('commerce.catalog.manage')<x-admin.button wire:click="create">New category</x-admin.button>@endcan</x-slot:actions>
    </x-admin.page-header>
    <x-admin.card title="Category hierarchy" description="Main category → group → subcategory. Drag within a parent or use the arrows to choose first/last order. Edit opens images, intro, hot products and FAQs.">
        @error('tree')<p role="alert" class="text-red-600">{{ $message }}</p>@enderror
        <div x-data="{ dragging: null }" class="mb-4">
            @include('livewire.admin.commerce.category-tree', ['nodes' => $parents->whereNull('parent_id')->values(), 'depth' => 0])
        </div>
    </x-admin.card>
    <x-admin.card title="Series collections" description="Separate from category parents. A product can belong to a category and a Series.">
        <div class="mb-4 flex flex-wrap gap-3">@foreach($series as $collection)<a class="rounded-lg border px-4 py-2 underline" href="{{ route('admin.commerce.series') }}">{{ $collection->name }}</a>@endforeach</div>
        <x-admin.button variant="secondary" :href="route('admin.commerce.series')">Series · Soon</x-admin.button>
    </x-admin.card>
    <p class="my-4 text-sm text-gray-600">Category names, active status and order are available to the connected storefront immediately. Page content changes require Save draft → Preview → Publish. Series are separate collections, not category ancestors.</p>
    <x-admin.card>
        <label class="wx-label" for="category-search">Search categories</label>
        <input id="category-search" type="search" wire:model.live.debounce.300ms="search" class="wx-input mb-4" placeholder="Search by category name">
        <p wire:loading class="text-sm" role="status">Updating categories…</p>
        <div class="space-y-3">
            @forelse($categories as $category)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800" wire:key="category-{{ $category->id }}">
                    <div class="min-w-0 break-words">
                        <p class="font-semibold">{{ $category->name }} <x-admin.badge :color="$category->is_active ? 'success' : 'gray'">{{ $category->is_active ? 'Active' : 'Draft / inactive' }}</x-admin.badge></p>
                        <p class="text-sm text-gray-500">{{ $category->slug }} · Position {{ $category->position }} · Parent: {{ $categoryPaths[$category->parent_id] ?? 'Top level' }}</p>
                        <p class="mt-2 text-sm">Published products: {{ $categoryCounts[$category->id]['direct_published'] }} direct · {{ $categoryCounts[$category->id]['branch_published'] }} in branch @can('commerce.catalog.manage')<br>Saved draft products: {{ $categoryCounts[$category->id]['direct_draft'] }} direct · {{ $categoryCounts[$category->id]['branch_draft'] }} in branch @endcan</p>
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
                <x-admin.field label="Parent category" for="form.parent_id"><select id="form.parent_id" wire:model.live="form.parent_id" class="wx-input"><option value="">Top level</option>@foreach($parents as $parent)@if(!in_array($parent->id, $excludedParents, true))<option value="{{ $parent->id }}">{{ $categoryPaths[$parent->id] }}</option>@endif@endforeach</select></x-admin.field>
                @if($editingId)
                    <x-admin.button variant="secondary" wire:click="previewMove">Preview move impact</x-admin.button>
                    @error('tree')<p role="alert" class="text-red-600">{{ $message }}</p>@enderror
                    @error('move')<p role="alert" class="text-red-600">{{ $message }}</p>@enderror
                    @if($moveImpact)
                        <div class="rounded-lg border p-3 space-y-2 text-sm" role="status">
                            <p>Move from: {{ $moveImpact['from'] }}</p><p>Destination: {{ $moveImpact['to'] }}</p>
                            <p>{{ $moveImpact['descendants'] }} descendant categories · {{ $moveImpact['draft_products'] }} saved draft products · {{ $moveImpact['published_products'] }} published products in this branch.</p>
                            <p>Product assignments, category page revisions and slugs are retained. Ancestor navigation changes immediately for connected storefronts; this is not a draft-only move. Archived products are excluded from counts. Counts are distinct per branch and advisory, not reservations; product edits can continue.</p>
                            @if($moveImpact['inactive_destination'])<p class="text-amber-700">Warning: the destination has an inactive ancestor. This branch may be hidden in storefront navigation.</p>@endif
                            <label class="flex gap-2"><input type="checkbox" wire:model="confirmMove"> I reviewed this move and its storefront impact.</label>
                        </div>
                    @endif
                @endif
                <x-admin.field label="Description" for="form.description"><textarea id="form.description" wire:model="form.description" class="wx-input" rows="3" maxlength="3000"></textarea></x-admin.field>
                <x-admin.field label="Sort position" for="form.position"><input id="form.position" wire:model="form.position" type="number" min="0" max="100000" class="wx-input"></x-admin.field>
                <label class="flex gap-2"><input type="checkbox" wire:model="form.is_active"> Active in the storefront category hierarchy (page content requires publication)</label>
                <p class="text-sm text-gray-500">Deactivate instead of deleting to preserve future product links. Activity changes are audited. @if(!$editingId)Save and continue to images, formatted content, FAQs and featured products.@else Use Edit on the category row for images, content, FAQs and featured products.@endif</p>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                @if($editingId)<x-admin.button variant="secondary" :href="route('admin.commerce.categories.edit', $editingId).'#featured-products'">Edit page / featured products</x-admin.button>@endif
                <x-admin.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save category' : 'Create and continue to editor' }}</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

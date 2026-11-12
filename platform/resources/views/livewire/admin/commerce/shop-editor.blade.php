<div>
<x-admin.page-header title="Shop customization · Native section drafts" description="Save and preview native Shop configuration. Full storefront Shop customization/cutover remains Soon; live content, product prices and checkout are unchanged." />
@if($publishedId)<x-admin.button variant="secondary" :href="route('admin.commerce.shop-content.preview', $publishedId)">View native Shop publication</x-admin.button>@else<p class="mb-4">No native Shop publication yet.</p>@endif
@can('commerce.catalog.manage')
<div class="mt-4 space-y-4">
<x-admin.card title="Shop content and SEO"><div class="space-y-3">
<x-admin.field label="Heading" for="shop-heading"><input id="shop-heading" class="wx-input" wire:model="form.heading" maxlength="160"></x-admin.field>
<x-admin.field label="Introduction (plain text)" for="shop-intro"><textarea id="shop-intro" class="wx-input" wire:model="form.intro" rows="4" maxlength="5000"></textarea></x-admin.field>
<x-admin.field label="SEO title" for="shop-seo"><input id="shop-seo" class="wx-input" wire:model="form.seo_title" maxlength="160"></x-admin.field>
<x-admin.field label="SEO description" for="shop-description"><textarea id="shop-description" class="wx-input" wire:model="form.seo_description" maxlength="320"></textarea></x-admin.field>
</div></x-admin.card>
<x-admin.card title="Ordered Shop sections" description="Up to 12 sections. Only product-owned published native snapshots can supply products; no draft recommendations or fabricated prices.">
<div class="space-y-4">
@forelse($form['sections'] as $index => $section)
<div class="space-y-3 rounded-lg border p-3" wire:key="shop-section-{{ $index }}">
<h3 class="font-semibold">Section {{ $index + 1 }}</h3>
<label class="wx-label" for="shop-section-heading-{{ $index }}">Section heading</label><input id="shop-section-heading-{{ $index }}" wire:model="form.sections.{{ $index }}.heading" maxlength="120" class="wx-input">
<label class="wx-label" for="shop-section-type-{{ $index }}">Source</label><select id="shop-section-type-{{ $index }}" wire:model.live="form.sections.{{ $index }}.type" class="wx-input"><option value="manual">Manual products</option><option value="category">Category branch</option><option value="series">Published native Series</option><option value="hot">Manual Hot products</option><option value="featured">Featured products</option><option value="sale">Active base or variant sales (UTC)</option></select>
<label class="wx-label" for="shop-section-sort-{{ $index }}">Product order</label><select id="shop-section-sort-{{ $index }}" wire:model.live="form.sections.{{ $index }}.sort" class="wx-input"><option value="source">Source order (manual / Series order; otherwise native ID)</option><option value="name">Published name, then native ID</option><option value="featured">Published featured position, then native ID</option></select>
@if($section['type'] === 'sale')<p class="text-sm">Includes a product when its published base price or an explicit variant discount is currently active. UTC start is inclusive; end is exclusive. Results change at schedule boundaries without a rewrite. No live checkout price, stock or inherited variant discount is implied.</p>@endif
@if($section['type'] === 'manual')
<label class="wx-label" for="shop-section-products-{{ $index }}">Published products (multiple selection)</label><select id="shop-section-products-{{ $index }}" wire:model="form.sections.{{ $index }}.product_ids" multiple class="wx-input" size="5">@foreach($products as $product)<option value="{{ $product['id'] }}">{{ $product['name'] }}</option>@endforeach</select><x-admin.button variant="secondary" wire:click="clearProducts({{ $index }})">Clear section products</x-admin.button><p class="text-sm">Empty selection is allowed. Re-select to remove unavailable products. Use the selected-order arrows for manual source order; other sort modes override it without rewriting selection. Sorting happens before the section limit.</p>
<h4 class="font-semibold">Selected source order</h4>
@foreach($section['product_ids'] as $productIndex => $id)<div class="flex flex-wrap items-center gap-2 rounded border p-2"><span class="min-w-0 flex-1 break-words">{{ array_column($products, 'name', 'id')[$id] ?? 'Unavailable product #'.$id }}</span>@if($section['sort'] === 'source')@if($productIndex > 0)<x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, {{ $productIndex }}, -1)" aria-label="Move product {{ $productIndex + 1 }} earlier in section {{ $index + 1 }}">↑</x-admin.button>@endif @if($productIndex < count($section['product_ids']) - 1)<x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, {{ $productIndex }}, 1)" aria-label="Move product {{ $productIndex + 1 }} later in section {{ $index + 1 }}">↓</x-admin.button>@endif @endif</div>@endforeach
@elseif($section['type'] === 'category')
<label class="wx-label" for="shop-section-category-{{ $index }}">Category branch</label><select id="shop-section-category-{{ $index }}" wire:model="form.sections.{{ $index }}.category_id" class="wx-input"><option value="">Choose category</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ app(\App\Domain\Commerce\Services\CategoryImpact::class)->path($categories, $category->id) }}</option>@endforeach</select><p class="text-sm">Includes descendant and inactive assignments; this is native curation, not a stock/availability claim.</p>
@elseif($section['type'] === 'series')
<label class="wx-label" for="shop-section-series-{{ $index }}">Native Series publication</label><select id="shop-section-series-{{ $index }}" wire:model="form.sections.{{ $index }}.series_id" class="wx-input"><option value="">Choose published Series</option>@foreach($collections as $collection)<option value="{{ $collection['id'] }}">{{ $collection['heading'] }}</option>@endforeach</select>
@endif
<label class="wx-label" for="shop-section-limit-{{ $index }}">Maximum products (1–24)</label><input id="shop-section-limit-{{ $index }}" wire:model="form.sections.{{ $index }}.limit" type="number" min="1" max="24" class="wx-input">
<div class="flex flex-wrap gap-2">@if($index > 0)<x-admin.button variant="secondary" wire:click="moveSection({{ $index }}, -1)" aria-label="Move section {{ $index + 1 }} earlier">↑</x-admin.button>@endif @if($index < count($form['sections']) - 1)<x-admin.button variant="secondary" wire:click="moveSection({{ $index }}, 1)" aria-label="Move section {{ $index + 1 }} later">↓</x-admin.button>@endif<x-admin.button variant="secondary" wire:click="removeSection({{ $index }})">Remove section {{ $index + 1 }}</x-admin.button></div>
</div>
@empty<p>No sections configured. Add a section or save an explicit empty Shop draft.</p>@endforelse
<x-admin.button variant="secondary" wire:click="addSection">Add section</x-admin.button>
</div></x-admin.card>
<x-admin.card title="Save and review">
@foreach($errors->all() as $message)<p role="alert" class="text-red-600">{{ $message }}</p>@endforeach
<div class="flex flex-wrap gap-3"><x-admin.button wire:click="saveDraft" wire:loading.attr="disabled">Save Shop draft</x-admin.button>@if($revisionId)<x-admin.button variant="secondary" :href="route('admin.commerce.shop-content.preview', $revisionId)" target="_blank" rel="noopener">Open saved Shop preview</x-admin.button>@endif</div>
<p class="mt-3 text-sm">Preview shows saved content only. Automatic sources/Series resolve current publications; results may change after publication. Save and review again before publishing changed configuration. Active-sale membership and source/name/featured ordering are available natively. Heroes, sidebar/footer and public React rendering remain Soon.</p>
@can('commerce.catalog.publish')<div class="mt-4 space-y-3"><label class="flex gap-2"><input type="checkbox" wire:model="confirmPublish"> I reviewed the saved preview and confirm native publication only.</label><x-admin.button wire:click="publish">Publish native Shop configuration</x-admin.button>@if($publishedId)<label class="flex gap-2"><input type="checkbox" wire:model="confirmWithdraw"> Confirm withdrawal; retain history.</label><x-admin.button variant="secondary" wire:click="withdraw">Withdraw native Shop publication</x-admin.button>@endif</div>@endcan
</x-admin.card>
</div>
@else
<p class="mt-4">Only the current native publication is available to view-only staff. Shop draft configuration requires catalogue management permission.</p>
@endcan
</div>

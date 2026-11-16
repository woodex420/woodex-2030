<div>
<x-admin.page-header :title="'Series draft · '.$series->name" description="Native collection content with manual or automatic published-product membership. Does not change legacy Series identity, product classifications or the React storefront."><x-slot:actions><x-admin.button variant="secondary" :href="route('admin.commerce.series.organize')">Back to organizer</x-admin.button></x-slot:actions></x-admin.page-header>
<div class="grid gap-5 lg:grid-cols-2">
<x-admin.card title="Collection content">
<div class="space-y-4">
<x-admin.field label="Collection heading" for="series-heading"><input id="series-heading" wire:model="form.heading" class="wx-input" maxlength="160"></x-admin.field>
<x-admin.field label="Hero layout" for="series-layout"><select id="series-layout" wire:model="form.layout" class="wx-input"><option value="banner">Banner</option><option value="split">Split</option><option value="minimal">Minimal text-first</option></select></x-admin.field>
<x-admin.field label="Hero alternative text" for="series-hero-alt"><input id="series-hero-alt" wire:model="form.hero_alt" maxlength="200" class="wx-input"></x-admin.field>
<div class="rounded-lg border p-3 space-y-3">
<p>Hero: {{ $heroPath ? 'Private image staged / retained' : 'No hero image' }}. Selecting, staging or removing does not save the draft or change a published revision.</p>
<input type="file" wire:model="heroUpload" accept="image/jpeg,image/png,image/webp" aria-label="Upload Series hero" class="max-w-full">
@if($heroUpload)<x-admin.button variant="secondary" wire:click="$set('heroUpload', null)">Clear selected upload</x-admin.button>@endif
<x-admin.button variant="secondary" wire:click="uploadHero" wire:loading.attr="disabled">Stage uploaded hero</x-admin.button>
@if($heroPath)<x-admin.button variant="secondary" wire:click="removeHero">Remove staged hero</x-admin.button>@endif
@can('website.edit')
<label class="wx-label" for="series-hero-search">Website Media hero search</label><input id="series-hero-search" wire:model.live.debounce.300ms="heroSearch" class="wx-input" placeholder="Search image titles">
<div class="grid grid-cols-3 gap-2">@foreach($heroMedia ?? [] as $media)<button type="button" wire:click="selectHero({{ $media->id }})" class="rounded border p-2 text-sm" aria-label="Use hero {{ $media->title }}"><img src="{{ route('admin.commerce.website-library', $media, false) }}" alt="{{ $media->alt ?? '' }}" class="h-16 w-full object-contain"><span class="break-words">{{ $media->title }}</span></button>@endforeach</div>
@if($heroMedia){{ $heroMedia->links() }}@endif
<p class="text-sm">Search and paginate Website Media; unavailable or unsupported files are omitted. A private immutable copy is retained; shared originals are never overwritten or deleted.</p>
@endcan
</div>
<x-admin.field label="Introduction (plain text)" for="series-intro"><textarea id="series-intro" wire:model="form.intro" class="wx-input" rows="5" maxlength="5000"></textarea></x-admin.field>
<x-admin.field label="Bottom content (plain text)" for="series-bottom"><textarea id="series-bottom" wire:model="form.bottom_text" rows="5" maxlength="10000" class="wx-input"></textarea></x-admin.field>
<h3 class="font-semibold">Collection FAQs</h3>
@foreach($form['faqs'] as $index => $faq)<div class="space-y-2 rounded border p-3"><input wire:model="form.faqs.{{ $index }}.question" class="wx-input" maxlength="250" aria-label="FAQ question {{ $index + 1 }}"><textarea wire:model="form.faqs.{{ $index }}.answer" class="wx-input" maxlength="2500" aria-label="FAQ answer {{ $index + 1 }}"></textarea><x-admin.button variant="secondary" wire:click="removeFaq({{ $index }})">Remove FAQ</x-admin.button></div>@endforeach
<x-admin.button variant="secondary" wire:click="addFaq">Add FAQ</x-admin.button>
<x-admin.field label="SEO title" for="series-seo"><input id="series-seo" wire:model="form.seo_title" class="wx-input" maxlength="160"></x-admin.field>
<x-admin.field label="SEO description" for="series-description"><textarea id="series-description" wire:model="form.seo_description" class="wx-input" maxlength="320"></textarea></x-admin.field>
@foreach($errors->all() as $message)<p role="alert" class="text-red-600">{{ $message }}</p>@endforeach
<x-admin.button wire:click="saveDraft" wire:loading.attr="disabled">Save collection draft</x-admin.button>
@if($revisionId)<x-admin.button variant="secondary" :href="route('admin.commerce.series-content.preview', $revisionId)" target="_blank" rel="noopener">Open saved collection preview</x-admin.button>@endif
<p class="text-sm">Preview shows the saved revision, not unsaved edits. Native publication is separate from live storefront cutover. Native hero/layout/FAQ preview is available; public React collection rendering and full Shop configuration remain Soon.</p>
</div>
</x-admin.card>
<x-admin.card title="Product membership" description="Only current native publications are selectable. New private drafts never appear here. Selection order is collection order; no product classification is rewritten.">
<div class="space-y-3">
<x-admin.field label="Membership mode" for="membership-mode"><select id="membership-mode" class="wx-input" wire:model.live="form.membership_mode"><option value="manual">Manual selection</option><option value="automatic">Automatic published-product rules</option></select></x-admin.field>
@if($form['membership_mode'] === 'automatic')
<x-admin.field label="Category branch (includes descendants)" for="rule-category"><select id="rule-category" class="wx-input" wire:model="form.rule_category_id"><option value="">All published products</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ app(\App\Domain\Commerce\Services\CategoryImpact::class)->path($categories, $category->id) }}</option>@endforeach</select></x-admin.field>
<label class="flex gap-2"><input type="checkbox" wire:model="form.rule_hot"> Require manual Hot flag</label>
<label class="flex gap-2"><input type="checkbox" wire:model="form.rule_featured"> Require Featured flag</label>
<x-admin.field label="Automatic order" for="rule-sort"><select id="rule-sort" wire:model="form.rule_sort" class="wx-input"><option value="catalogue">Native product ID</option><option value="name">Published name (ID breaks ties)</option></select></x-admin.field>
<x-admin.field label="Maximum products" for="rule-limit"><input id="rule-limit" type="number" min="1" max="100" wire:model="form.rule_limit" class="wx-input"></x-admin.field>
<p class="text-sm">All enabled conditions must match the product's published snapshot. Draft flags/categories are ignored. Results update with current product publications and the category tree, even after collection publication. Save and open the exact preview to inspect results. Inactive category assignments may match; this is native curation, not storefront availability. Manual selections are retained but ignored in automatic mode.</p>
@else
@forelse($products as $product)<label class="flex gap-2 rounded-lg border p-3"><input type="checkbox" wire:model.live="form.product_ids" value="{{ $product['id'] }}">{{ $product['name'] }}</label>@empty<p>No published native products are available. You may save an empty collection; product review and publication remain separate.</p>@endforelse
<h3 class="font-semibold">Selected order</h3>
@foreach($form['product_ids'] as $index => $id)
<div class="flex flex-wrap items-center gap-2 rounded-lg border p-2" wire:key="series-selection-{{ $id }}"><span class="min-w-0 flex-1 break-words">{{ array_column($products, 'name', 'id')[$id] ?? 'Unavailable product #'.$id }}</span><x-admin.button variant="secondary" wire:click="removeProduct({{ $index }})" aria-label="Remove selected product {{ $index + 1 }}">Remove</x-admin.button>@if($index > 0)<x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, -1)" aria-label="Move selected product {{ $index + 1 }} earlier">↑</x-admin.button>@endif @if($index < count($form['product_ids']) - 1)<x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, 1)" aria-label="Move selected product {{ $index + 1 }} later">↓</x-admin.button>@endif</div>
@endforeach
@endif
</div>
</x-admin.card>
</div>
@can('commerce.catalog.publish')
<div class="mt-5"><x-admin.card title="Native publication">
<div class="space-y-3"><label class="flex gap-2"><input type="checkbox" wire:model="confirmPublish"> I reviewed the exact saved collection preview and confirm native publication.</label><x-admin.button wire:click="publish" wire:loading.attr="disabled">Publish native collection</x-admin.button>
@if($series->published_revision_id)<p>Published native revision {{ $series->published_revision_id }}. Saving another draft does not replace it.</p><label class="flex gap-2"><input type="checkbox" wire:model="confirmWithdraw"> Confirm withdrawal; keep all history.</label><x-admin.button variant="secondary" wire:click="withdraw">Withdraw native collection</x-admin.button>@endif</div>
</x-admin.card></div>
@endcan
</div>

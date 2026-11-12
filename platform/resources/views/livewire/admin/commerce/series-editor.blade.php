<div>
<x-admin.page-header :title="'Series draft · '.$series->name" description="Manual native collection content and ordered membership. Does not change legacy Series identity, product classifications or the React storefront."><x-slot:actions><x-admin.button variant="secondary" :href="route('admin.commerce.series.organize')">Back to organizer</x-admin.button></x-slot:actions></x-admin.page-header>
<div class="grid gap-5 lg:grid-cols-2">
<x-admin.card title="Collection content">
<div class="space-y-4">
<x-admin.field label="Collection heading" for="series-heading"><input id="series-heading" wire:model="form.heading" class="wx-input" maxlength="160"></x-admin.field>
<x-admin.field label="Introduction (plain text)" for="series-intro"><textarea id="series-intro" wire:model="form.intro" class="wx-input" rows="5" maxlength="5000"></textarea></x-admin.field>
<x-admin.field label="SEO title" for="series-seo"><input id="series-seo" wire:model="form.seo_title" class="wx-input" maxlength="160"></x-admin.field>
<x-admin.field label="SEO description" for="series-description"><textarea id="series-description" wire:model="form.seo_description" class="wx-input" maxlength="320"></textarea></x-admin.field>
@foreach($errors->all() as $message)<p role="alert" class="text-red-600">{{ $message }}</p>@endforeach
<x-admin.button wire:click="saveDraft" wire:loading.attr="disabled">Save collection draft</x-admin.button>
@if($revisionId)<x-admin.button variant="secondary" :href="route('admin.commerce.series-content.preview', $revisionId)" target="_blank" rel="noopener">Open saved collection preview</x-admin.button>@endif
<p class="text-sm">Preview shows the saved revision, not unsaved edits. Native publication is separate from live storefront cutover. Hero media, automatic collection rules and full Shop configuration remain Soon.</p>
</div>
</x-admin.card>
<x-admin.card title="Manual product membership" description="Only current native publications are selectable. New private drafts never appear here. Selection order is collection order; no product classification is rewritten.">
<div class="space-y-3">
@forelse($products as $product)<label class="flex gap-2 rounded-lg border p-3"><input type="checkbox" wire:model.live="form.product_ids" value="{{ $product['id'] }}">{{ $product['name'] }}</label>@empty<p>No published native products are available. You may save an empty collection; product review and publication remain separate.</p>@endforelse
<h3 class="font-semibold">Selected order</h3>
@foreach($form['product_ids'] as $index => $id)
<div class="flex flex-wrap items-center gap-2 rounded-lg border p-2" wire:key="series-selection-{{ $id }}"><span class="min-w-0 flex-1 break-words">{{ array_column($products, 'name', 'id')[$id] ?? 'Unavailable product #'.$id }}</span><x-admin.button variant="secondary" wire:click="removeProduct({{ $index }})" aria-label="Remove selected product {{ $index + 1 }}">Remove</x-admin.button>@if($index > 0)<x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, -1)" aria-label="Move selected product {{ $index + 1 }} earlier">↑</x-admin.button>@endif @if($index < count($form['product_ids']) - 1)<x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, 1)" aria-label="Move selected product {{ $index + 1 }} later">↓</x-admin.button>@endif</div>
@endforeach
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

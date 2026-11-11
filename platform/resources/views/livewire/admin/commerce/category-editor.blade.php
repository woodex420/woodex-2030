<div>
    <x-admin.page-header :title="'Edit category · '.$category->name" description="Draft-first category content. Published content is delivered to connected storefronts; drafts stay private.">
        <x-slot:actions><x-admin.button variant="secondary" :href="route('admin.commerce.categories')">Back to Categories</x-admin.button></x-slot:actions>
    </x-admin.page-header>
    <p class="mb-4 text-sm">Category: {{ $category->slug }} · Content version {{ $version }} · {{ $category->published_revision_id ? 'Native published revision #'.$category->published_revision_id : 'No native publication yet' }}. Office/Home are category parents; Series remain separate collections.</p>
    @if($errors->any())<div role="alert" class="mb-4 rounded-lg border border-error-500 p-4"><h2 class="font-semibold">Please correct these issues</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form wire:submit="saveDraft" class="space-y-5">
        <x-admin.card title="Page metadata" description="Preview always remains noindex. These settings drive the published category page on connected storefronts.">
            <div class="grid gap-4 md:grid-cols-2">
                @foreach(['seo_title' => 'SEO title', 'seo_description' => 'Meta description', 'og_title' => 'Social / Open Graph title', 'og_description' => 'Social / Open Graph description', 'canonical_path' => 'Canonical local path'] as $key => $label)
                    <x-admin.field :label="$label" :for="'form.'.$key"><input id="form.{{ $key }}" wire:model="form.{{ $key }}" class="wx-input"></x-admin.field>
                @endforeach
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.allow_indexing"> Allow indexing on the eventual public integration (never the preview)</label>
            </div>
        </x-admin.card>
        <x-admin.card title="Hero template and content">
            <div class="grid gap-4 md:grid-cols-2">
                <x-admin.field label="Hero template" for="form.hero_template"><select id="form.hero_template" wire:model="form.hero_template" class="wx-input"><option value="banner">Banner</option><option value="split">Split image / text</option><option value="minimal">Minimal text</option></select></x-admin.field>
                <x-admin.field label="Heading" for="form.hero_heading"><input id="form.hero_heading" wire:model="form.hero_heading" class="wx-input"></x-admin.field>
                <x-admin.field label="Hero text" for="form.hero_text"><textarea id="form.hero_text" wire:model="form.hero_text" rows="4" class="wx-input"></textarea></x-admin.field>
                <div class="space-y-3">
                    <x-admin.field label="Hero image (JPEG, PNG or WebP; up to 4 MB and 6000 × 6000)" for="heroUpload"><input id="heroUpload" type="file" accept="image/jpeg,image/png,image/webp" wire:model="heroUpload" class="wx-input"></x-admin.field>
                    <p wire:loading wire:target="heroUpload" role="status">Uploading image…</p>
                    @if($heroUpload && !$errors->has('heroUpload'))<img class="max-h-48 rounded-lg" src="{{ $heroUpload->temporaryUrl() }}" alt="New hero upload preview">@elseif($heroPath && $savedRevisionId)<img class="max-h-48 rounded-lg" src="{{ route('admin.commerce.category-content.hero', $savedRevisionId) }}" alt="{{ $form['hero_alt'] }}">@endif
                    @if(!$heroPath && !$heroUpload)<p class="text-sm text-gray-500">No category image has been saved. Upload the actual category image here; product photos are not assigned automatically.</p>@endif
                    @if($heroPath || $heroUpload)<x-admin.button variant="secondary" wire:click="removeHero">Remove image from this draft</x-admin.button>@endif
                    <x-admin.field label="Image alternative text" for="form.hero_alt"><input id="form.hero_alt" wire:model="form.hero_alt" class="wx-input"></x-admin.field>
                </div>
                <x-admin.field label="Button label (optional)" for="form.cta_label"><input id="form.cta_label" wire:model="form.cta_label" class="wx-input"></x-admin.field>
                <x-admin.field label="Button local path" for="form.cta_path"><input id="form.cta_path" wire:model="form.cta_path" placeholder="/shop?segment=office" class="wx-input"></x-admin.field>
            </div>
        </x-admin.card>
        <div class="rounded-lg border p-4 text-sm"><p>Existing category text was not automatically imported. Load available storefront copy into an empty introduction, review it, then save a draft. Category-specific images must be uploaded; unrelated product photos are never substituted.</p><x-admin.button variant="secondary" wire:click="loadSourceText">Load existing storefront introduction</x-admin.button></div>
        <x-admin.card title="Introduction and bottom content" description="Format paragraphs with headings, bold, italics, lists and links. Markdown is saved; raw HTML and unsafe links are blocked.">
            <div class="space-y-4">
                @foreach(['intro_heading' => 'Introduction heading', 'bottom_heading' => 'Bottom content heading'] as $key => $label)<x-admin.field :label="$label" :for="'form.'.$key"><input id="form.{{ $key }}" wire:model="form.{{ $key }}" class="wx-input"></x-admin.field>@endforeach
                <x-admin.field label="Text format" for="form.text_format"><select id="form.text_format" wire:model.live="form.text_format" class="wx-input"><option value="markdown">Formatted text (Markdown)</option><option value="plain">Plain text (legacy content)</option></select></x-admin.field>
                <x-admin.content-editor label="Introduction text" field="intro_text" :value="$form['intro_text']" :format="$form['text_format']" />
                <x-admin.content-editor label="Bottom content" field="bottom_text" :value="$form['bottom_text']" :format="$form['text_format']" />
            </div>
        </x-admin.card>
        <x-admin.card title="Featured / Hot Products carousel" description="Repository catalogue reference only—not live inventory or verified sales popularity. Eligible products belong to this category or its descendants. Connected storefronts resolve selected references to their existing product cards, images and prices.">
            <div class="grid gap-4 md:grid-cols-2">
                <x-admin.field label="Section heading" for="form.carousel_heading"><input id="form.carousel_heading" wire:model="form.carousel_heading" class="wx-input"></x-admin.field>
                <x-admin.field label="Selection mode" for="form.carousel_mode"><select id="form.carousel_mode" wire:model.live="form.carousel_mode" class="wx-input"><option value="off">Hidden</option><option value="manual">Manual selection</option><option value="automatic">Automatic category selection</option></select></x-admin.field>
                <x-admin.field label="Maximum cards (1–12)" for="form.carousel_limit"><input id="form.carousel_limit" type="number" min="1" max="12" wire:model="form.carousel_limit" class="wx-input"></x-admin.field>
                <x-admin.field label="Automatic sorting" for="form.carousel_sort"><select id="form.carousel_sort" wire:model="form.carousel_sort" class="wx-input"><option value="catalogue">Source catalogue order</option><option value="name">Name A–Z</option></select></x-admin.field>
            </div>
            @if($form['carousel_mode'] === 'manual')
                <x-admin.field label="Select up to 12 eligible products, then order them below" for="form.product_ids"><select id="form.product_ids" multiple size="8" wire:model.live="form.product_ids" class="wx-input mt-3">@foreach($eligible as $product)<option value="{{ $product['id'] }}">{{ $product['name'] }}</option>@endforeach</select></x-admin.field>
                <ol class="mt-3 space-y-2">@foreach($form['product_ids'] as $index => $productId)<li class="flex flex-wrap items-center gap-2" wire:key="featured-{{ $productId }}"><span>{{ collect($eligible)->firstWhere('id', $productId)['name'] ?? $productId }}</span><x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, -1)" aria-label="Move product up">↑</x-admin.button><x-admin.button variant="secondary" wire:click="moveProduct({{ $index }}, 1)" aria-label="Move product down">↓</x-admin.button></li>@endforeach</ol>
            @endif
            @if(count($eligible) === 0)<p class="mt-3 text-sm">No eligible reference products. This category can still have content and FAQs; no products will be invented.</p>@endif
        </x-admin.card>
        <x-admin.card title="Frequently asked questions" description="Add, remove and reorder up to 20 FAQs. Removal affects only the next saved draft, never an older revision.">
            <div class="space-y-4">@foreach($form['faqs'] as $index => $faq)
                <fieldset class="rounded-lg border border-gray-200 p-4" wire:key="faq-{{ $index }}"><legend>FAQ {{ $index + 1 }}</legend>
                    <x-admin.field label="Question" :for="'faq-question-'.$index"><input id="faq-question-{{ $index }}" wire:model="form.faqs.{{ $index }}.question" class="wx-input"></x-admin.field>
                    <x-admin.field label="Answer" :for="'faq-answer-'.$index"><textarea id="faq-answer-{{ $index }}" wire:model="form.faqs.{{ $index }}.answer" rows="3" class="wx-input"></textarea></x-admin.field>
                    <div class="mt-3 flex gap-2"><x-admin.button variant="secondary" wire:click="moveFaq({{ $index }}, -1)" aria-label="Move FAQ up">↑</x-admin.button><x-admin.button variant="secondary" wire:click="moveFaq({{ $index }}, 1)" aria-label="Move FAQ down">↓</x-admin.button><x-admin.button variant="danger" wire:click="removeFaq({{ $index }})">Remove FAQ</x-admin.button></div>
                </fieldset>
            @endforeach</div>
            <x-admin.button variant="secondary" wire:click="addFaq">Add FAQ</x-admin.button>
        </x-admin.card>
        <div class="flex flex-wrap gap-3"><x-admin.button type="submit" wire:loading.attr="disabled" wire:target="saveDraft,heroUpload">Save draft</x-admin.button>@if($savedRevisionId)<x-admin.button variant="secondary" :href="route('admin.commerce.category-content.preview', $savedRevisionId)" target="_blank" rel="noopener noreferrer">Preview saved revision #{{ $savedRevisionId }}</x-admin.button>@endif</div>
    </form>
    @can('commerce.catalog.publish')
        <div class="mt-5 space-y-3 rounded-lg border border-gray-200 p-4">
            <label class="flex gap-2"><input type="checkbox" wire:model="confirmPublish"> I previewed the saved revision and approve publishing it to connected storefronts. Connected storefronts will display the published revision.</label>
            <x-admin.button wire:click="publish" wire:loading.attr="disabled" wire:target="publish">Publish native snapshot</x-admin.button>
            @if($category->published_revision_id)<label class="flex gap-2"><input type="checkbox" wire:model="confirmUnpublish"> Confirm removing this native publication, while retaining all revisions.</label><x-admin.button variant="danger" wire:click="unpublish" wire:loading.attr="disabled" wire:target="unpublish">Unpublish native snapshot</x-admin.button>@endif
        </div>
    @endcan
    <div class="mt-5"><x-admin.card title="Saved revision history" description="Latest ten immutable snapshots. Open any saved preview; only the current saved draft is publishable."><ul class="space-y-2">@forelse($revisions as $revision)<li><a class="underline" href="{{ route('admin.commerce.category-content.preview', $revision) }}" target="_blank" rel="noopener noreferrer">Revision #{{ $revision->id }}</a> <x-admin.button variant="secondary" wire:click="restoreAsDraft({{ $revision->id }})">Use as new draft</x-admin.button> · {{ $revision->created_at->format('Y-m-d H:i') }} @if($category->draft_revision_id === $revision->id) · Current draft @endif @if($category->published_revision_id === $revision->id) · Native published @endif</li>@empty<li>No saved revisions yet.</li>@endforelse</ul></x-admin.card></div>
</div>

<x-admin.modal name="showMediaLibrary" title="Add media to product gallery" width="max-w-6xl">
<p class="mb-4 text-sm text-gray-500">Choose from media library. Select multiple images, inspect details and arrange the selection before adding. Images are staged until Save draft; new products first receive a private draft owner record. Publishing is always separate. Video gallery support is not enabled yet.</p>
<div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Media source tabs">
<x-admin.button variant="secondary" wire:click="$set('mediaTab', 'library')" :aria-pressed="$mediaTab === 'library' ? 'true' : 'false'">Media Library</x-admin.button>
<x-admin.button variant="secondary" wire:click="$set('mediaTab', 'upload')" :aria-pressed="$mediaTab === 'upload' ? 'true' : 'false'">Upload files</x-admin.button>
</div>
@if($errors->any())<div role="alert" class="mb-4 rounded-lg border p-3">@foreach($errors->all() as $error)<p class="text-red-600">{{ $error }}</p>@endforeach</div>@endif
@if($mediaTab === 'upload')
<div class="space-y-3 rounded-xl border p-4"><label class="wx-label" for="picker-uploads">Upload private product images</label><input id="picker-uploads" type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="uploads" class="wx-input"><p class="text-sm text-gray-500">JPEG/PNG/WebP · 4 MB and 6000×6000 each · 24 gallery images maximum. This existing upload action saves the draft and uploaded images; it does not publish or change shared website originals.</p><x-admin.button wire:click="uploadImages" wire:loading.attr="disabled" wire:target="uploads,uploadImages">Upload and save gallery draft</x-admin.button><p wire:loading wire:target="uploads,uploadImages" role="status">Uploading images…</p></div>
@else
<div class="grid gap-5 lg:grid-cols-3">
<div class="min-w-0 lg:col-span-2">
<div class="mb-4 grid gap-3 sm:grid-cols-2"><input aria-label="Search media library" wire:model.live.debounce.300ms="mediaSearch" class="wx-input" placeholder="Search product name / website image title">@can('website.edit')<select aria-label="Website media folder" wire:model.live="mediaFolder" class="wx-input"><option value="">All website folders</option>@foreach($mediaFolders as $folder)<option value="{{ $folder }}">{{ $folder }}</option>@endforeach</select>@endcan</div>
<div class="max-h-[55vh] space-y-5 overflow-y-auto p-1">
<section><h3 class="font-semibold">Private product media</h3><p class="mb-3 text-xs text-gray-500">Images from active products, deduplicated by content. Archived originals remain retained. CRM attachments are never included.</p>
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3">@forelse($library as $item)
<div wire:key="picker-product-{{ $item->id }}" class="rounded-xl border p-2"><label class="block cursor-pointer"><input type="checkbox" wire:model.live="librarySelection" value="product:{{ $item->id }}" aria-label="Select product image {{ $item->id }}"><img class="h-24 w-full object-contain" src="{{ route('admin.commerce.product-media', $item, false) }}" alt="Product image {{ $item->id }}" loading="lazy"><span class="text-xs">Product image #{{ $item->id }}</span></label><button type="button" class="mt-2 text-sm underline" wire:click="inspectLibraryItem('product:{{ $item->id }}')">Details</button></div>
@empty<p class="col-span-full text-sm text-gray-500">No matching private product images.</p>@endforelse</div>
@if($library instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<div class="mt-3">{{ $library->links() }}</div>@endif
</section>
@can('website.edit')<section><h3 class="font-semibold">Website Media library</h3><p class="mb-3 text-xs text-gray-500">Reuses the existing Laravel library. Supported images are copied to private product references; shared originals are unchanged.</p>
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3">@forelse($websiteLibrary as $item)
<div wire:key="picker-website-{{ $item->id }}" class="rounded-xl border p-2"><label class="block cursor-pointer"><input type="checkbox" wire:model.live="librarySelection" value="website:{{ $item->id }}" aria-label="Select website image {{ $item->id }}"><img class="h-24 w-full object-contain" src="{{ route('admin.commerce.website-library', $item, false) }}" alt="{{ $item->alt }}" loading="lazy"><span class="break-words text-xs">{{ $item->title ?: 'Website image #'.$item->id }}</span></label><button type="button" class="mt-2 text-sm underline" wire:click="inspectLibraryItem('website:{{ $item->id }}')">Details</button></div>
@empty<p class="col-span-full text-sm text-gray-500">No supported images on this page. Search or use another page. JPEG/PNG/WebP up to 4 MB.</p>@endforelse</div>
@if($websiteLibrary instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<div class="mt-3">{{ $websiteLibrary->links() }}</div>@endif
</section>@endcan
</div>
</div>
<aside class="min-w-0 space-y-4 rounded-xl border bg-gray-50 p-4 dark:bg-gray-900" aria-label="Media attachment details and selection">
<h3 class="font-semibold">{{ count($librarySelection) }} selected</h3><p class="text-xs text-gray-500">Added after existing gallery slots, in this order. Repeated photos are skipped. First slot is primary; reorder or remove later in Gallery settings.</p>
<ol class="max-h-40 space-y-2 overflow-y-auto">@foreach(array_slice($librarySelection, 0, 24) as $index => $token)<li wire:key="selected-library-{{ $token }}" class="flex flex-wrap items-center gap-2 text-xs"><span class="break-all">{{ $index+1 }}. {{ $token }}</span><button type="button" wire:click="moveLibrarySelection({{ $index }}, -1)" aria-label="Move selection {{ $index+1 }} earlier">↑</button><button type="button" wire:click="moveLibrarySelection({{ $index }}, 1)" aria-label="Move selection {{ $index+1 }} later">↓</button></li>@endforeach</ol>
<x-admin.button variant="secondary" wire:click="$set('librarySelection', [])">Clear selection</x-admin.button>
@if($libraryDetails)<div class="space-y-2 border-t pt-4"><h4 class="font-semibold">Attachment details</h4><img class="h-40 w-full object-contain" src="{{ $libraryDetails['source'] === 'website' ? route('admin.commerce.website-library', $libraryDetails['id'], false) : route('admin.commerce.product-media', $libraryDetails['id'], false) }}" alt="{{ $libraryDetails['alt'] }}"><p class="break-words text-sm">{{ $libraryDetails['title'] }}</p><p class="text-xs">{{ $libraryDetails['width'] }}×{{ $libraryDetails['height'] }} · {{ number_format($libraryDetails['bytes']/1024, 1) }} KB</p><p class="text-xs">Alternative text: {{ $libraryDetails['alt'] ?: 'Needs review after adding' }}</p></div>@else<p class="text-sm text-gray-500">Use Details on an image to inspect its actual dimensions, file size and alternative text.</p>@endif
</aside></div>
@endif
<x-slot:footer><span class="text-sm text-gray-500">{{ count($form['images']) }}/24 current gallery slots</span><x-admin.button variant="secondary" wire:click="$set('showMediaLibrary', false)">Cancel</x-admin.button>@if($mediaTab !== 'upload')<x-admin.button wire:click="addSelectedLibraryImages" wire:loading.attr="disabled" :disabled="count($librarySelection) === 0">Add selected to gallery</x-admin.button>@endif</x-slot:footer>
</x-admin.modal>

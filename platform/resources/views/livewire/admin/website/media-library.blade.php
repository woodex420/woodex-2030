@use('App\Domain\Website\Models\Media')

<div>
    <x-admin.page-header title="Media" description="Every photograph on the public website. Alt text is not optional: it is what a screen reader reads aloud, and the only description of an image that search engines and AI assistants ever see. Saving it writes the wording into the pages that show the photo."
                         :breadcrumbs="['Website' => null, 'Media' => null]" />

    <x-admin.card title="Add images" description="JPG, PNG, WebP or AVIF, up to 5 MB each. Large photographs slow a page down: keep them under 300 KB where you can.">
        <div class="flex flex-wrap items-end gap-3">
            <x-admin.field label="Choose images" class="grow">
                <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp,image/avif" class="wx-input">
                @error('uploads.*') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
            </x-admin.field>
            <x-admin.button icon="upload" wire:click="save" :disabled="$uploads === []">Upload</x-admin.button>
        </div>
        <div wire:loading wire:target="uploads" class="mt-2 text-theme-xs text-gray-500">Reading images…</div>
    </x-admin.card>

    <div class="mt-6 mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, description or file" class="wx-input pl-10" aria-label="Search images">
        </div>
        <label class="flex items-center gap-2 text-theme-sm text-gray-700 md:col-span-2 dark:text-gray-300">
            <input type="checkbox" wire:model.live="onlyMissingAlt" class="rounded border-gray-300">
            Show only images without alt text
            <span class="text-theme-xs text-gray-500">({{ $missingAlt }} of {{ $total }})</span>
        </label>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($images as $image)
            <div wire:key="media-{{ $image->id }}" class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <a href="{{ $image->url() }}" target="_blank" rel="noopener" class="block">
                    <img src="{{ $image->url() }}" alt="{{ $image->alt }}" loading="lazy" class="aspect-[4/3] w-full bg-gray-50 object-cover">
                </a>
                <div class="space-y-2 p-4">
                    <x-admin.field label="Alt text" hint="{{ strlen($alt[$image->id] ?? '') }} characters · describe what the photo shows, then press Save to write it into the pages.">
                        <input type="text" wire:model.blur="alt.{{ $image->id }}" class="wx-input @if (trim($alt[$image->id] ?? '') === '') border-warning-400 @endif" placeholder="Warm living room with timber panelling and linen seating">
                    </x-admin.field>
                    <x-admin.field label="Name (for the picker)">
                        <input type="text" wire:model.blur="titles.{{ $image->id }}" class="wx-input">
                    </x-admin.field>
                    <p class="text-theme-xs text-gray-500">{{ $image->width }}×{{ $image->height }} · {{ basename($image->path) }}
                        @if ($image->disk === 'site') <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/10">theme file</span> @endif
                    </p>
                    <div class="flex items-center justify-between gap-2">
                        <x-admin.button size="sm" variant="secondary" wire:click="updateMeta({{ $image->id }})">Save</x-admin.button>
                        @if ($image->disk !== 'site')
                            <button type="button" wire:click="delete({{ $image->id }})" wire:confirm="Delete this image? It is also removed from the server." class="text-theme-sm text-error-600 hover:underline">Delete</button>
                        @else
                            <span class="text-theme-xs text-gray-500">Shipped with the design</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="sm:col-span-2 xl:col-span-3">
                <x-admin.empty icon="image" title="No images yet" description="Upload the studio photographs, then give each one a description." />
            </div>
        @endforelse
    </div>

    <div class="mt-6">{{ $images->links() }}</div>
</div>

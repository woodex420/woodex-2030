@props(['files', 'canEdit' => false])
{{-- Files card (GLB-006) for components using App\Livewire\Concerns\ManagesAttachments. --}}
<x-admin.card title="Files ({{ $files->count() }})">
    @if ($canEdit)
        <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-gray-300 px-4 py-5 text-center hover:border-navy-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5">
            <x-icon name="upload" class="size-5 text-gray-400" />
            <span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Add photos, drawings or documents</span>
            <span class="text-theme-xs text-gray-500">JPG, PNG, PDF, DWG/DXF, Excel, Word · up to 20 MB each</span>
            <input type="file" wire:model="uploads" multiple class="sr-only" accept=".{{ implode(',.', \App\Domain\Crm\Models\Lead::ATTACHMENT_EXTENSIONS) }}">
        </label>
        <div wire:loading wire:target="uploads" class="mt-2 text-theme-xs text-gray-500">Uploading…</div>
        @error('uploads') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror
        @error('uploads.*') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror
    @endif

    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
        @forelse ($files as $file)
            @php($isImage = str_starts_with((string) $file->mime_type, 'image/'))
            <li wire:key="file-{{ $file->id }}" class="flex items-center gap-3 py-2.5">
                <a href="{{ route('admin.media.show', $file) }}" target="_blank" rel="noopener" class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5">
                    @if ($isImage && $file->hasGeneratedConversion('thumb'))
                        <img src="{{ route('admin.media.show', [$file, 'thumb']) }}" alt="" width="44" height="44" class="size-full object-cover" loading="lazy">
                    @else
                        <x-icon :name="$isImage ? 'image' : 'file'" class="size-5 text-gray-400" />
                    @endif
                </a>
                <div class="min-w-0 flex-1">
                    <a href="{{ route('admin.media.show', $file) }}" target="_blank" rel="noopener" class="block truncate text-theme-sm font-medium text-gray-800 hover:underline dark:text-white/90">{{ $file->getCustomProperty('original_name', $file->file_name) }}</a>
                    <p class="text-theme-xs text-gray-500">{{ $file->human_readable_size }} · {{ $file->created_at?->format('j M Y') }}</p>
                </div>
                <a href="{{ route('admin.media.show', ['media' => $file, 'download' => 1]) }}" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5" title="Download"><x-icon name="download" class="size-4" /></a>
                @if ($canEdit)
                    <button type="button" wire:click="deleteAttachment({{ $file->id }})" wire:confirm="Delete this file?" class="rounded-lg p-1.5 text-gray-400 hover:bg-error-50 hover:text-error-600" title="Delete"><x-icon name="trash" class="size-4" /></button>
                @endif
            </li>
        @empty
            <li class="py-3 text-center text-theme-sm text-gray-500">No files yet.</li>
        @endforelse
    </ul>
</x-admin.card>

<div>
    <x-admin.page-header title="Description library" description="Reusable Interior and Furniture specifications, grouped by section. Copy text into a quote or a new rate book item; existing copies never change." :breadcrumbs="['Sales' => null, 'Description library' => null]">
        <x-slot:actions>@if ($canEdit)<x-admin.button icon="plus" wire:click="openCreate">New description</x-admin.button>@endif</x-slot:actions>
    </x-admin.page-header>
    <div class="mb-5 grid gap-3 md:grid-cols-3">
        <input type="search" wire:model.live.debounce.300ms="search" class="wx-input" placeholder="Search descriptions" aria-label="Search descriptions">
        <select wire:model.live="line" class="wx-input" aria-label="Business line"><option value="">Both brands</option value="interior">Interior / Project</option><option value="furniture">Furniture</option></select>
        <select wire:model.live="section" class="wx-input" aria-label="Section"><option value="">All sections</option>@foreach ($sections as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($templates as $template)
            <x-admin.card wire:key="description-{{ $template->id }}">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2"><span class="text-theme-xs text-gray-500">{{ $template->business_line->label() }} · {{ $template->section }}{{ $template->is_active ? '' : ' · Archived' }}</span>@if ($canEdit)<button type="button" wire:click="openEdit({{ $template->id }})" class="text-theme-sm text-brand-600 hover:underline">Edit</button>@endif</div>
                <h2 class="mb-2 font-medium text-gray-900 dark:text-white">{{ $template->title }}</h2>
                <p class="mb-4 whitespace-pre-line break-words text-theme-sm text-gray-600 dark:text-gray-300">{{ $template->body }}</p>
                @if ($canEdit && $template->is_active)<x-admin.button size="sm" variant="secondary" :href="route('admin.sales.documents', ['tab' => 'rate-book', 'description' => $template->id])" wire:navigate>New rate book item</x-admin.button>@endif
            </x-admin.card>
        @empty
            <x-admin.card><x-admin.empty icon="book" title="No descriptions match" description="Change your filters or create a reusable description." /></x-admin.card>
        @endforelse
    </div>
    <div class="mt-5">{{ $templates->links('components.admin.pagination') }}</div>
    @if ($showForm)
        <x-admin.modal name="showForm" :title="$editingId ? 'Edit description' : 'New description'" submit="save" width="max-w-2xl">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field label="Brand" for="form.business_line"><select id="form.business_line" wire:model="form.business_line" class="wx-input"><option value="interior">Interior / Project</option><option value="furniture">Furniture</option></select></x-admin.field>
                <x-admin.field label="Section" for="form.section"><input id="form.section" wire:model="form.section" class="wx-input" maxlength="80"></x-admin.field>
                <x-admin.field label="Title" for="form.title" class="sm:col-span-2"><input id="form.title" wire:model="form.title" class="wx-input" maxlength="100"></x-admin.field>
                <x-admin.field label="Description / specification" for="form.body" hint="Plain text, up to 500 characters to match the current rate book. Review specifications before sending." class="sm:col-span-2"><textarea id="form.body" wire:model="form.body" class="wx-input h-auto py-2.5" rows="6" maxlength="500"></textarea></x-admin.field>
                <label class="flex items-center gap-2 text-theme-sm text-gray-600"><input type="checkbox" wire:model="form.is_active" class="wx-checkbox">Active (available to copy)</label>
            </div>
            <x-slot:footer><x-admin.button type="submit" wire:loading.attr="disabled">Save description</x-admin.button></x-slot:footer>
        </x-admin.modal>
    @endif
</div>

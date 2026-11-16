@use('App\Support\Money')
@php $canEdit = auth()->user()->can('manageCatalog', \App\Domain\Sales\Models\Quote::class); @endphp
<div>
    <x-admin.page-header title="Rate book" description="Catalogue rates the quote builder copies from. Changing a rate never changes quotations that already use it."
                         :breadcrumbs="['Sales' => null, 'Rate book' => null]">
        <x-slot:actions>
            @if ($canEdit)
                <x-admin.button icon="plus" wire:click="openCreate">New item</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <div class="relative md:col-span-2">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search description or category" class="wx-input pl-10" aria-label="Search the rate book">
        </div>
        <select wire:model.live="category" class="wx-input" aria-label="Category">
            <option value="">All categories</option>
            @foreach ($categories as $name)
                <option value="{{ $name }}">{{ $name }}</option>
            @endforeach
        </select>
        <select wire:model.live="state" class="wx-input" aria-label="Status">
            <option value="active">Available in the builder</option>
            <option value="inactive">Hidden</option>
            <option value="all">All items</option>
        </select>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Item</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Category</th>
                        <th class="px-5 py-3 text-right font-medium">Rate</th>
                        <th class="hidden px-5 py-3 text-right font-medium lg:table-cell">Cost (staff)</th>
                        @if ($canEdit)<th class="px-5 py-3"><span class="sr-only">Actions</span></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($items as $item)
                        <tr wire:key="catalog-{{ $item->id }}" @class(['hover:bg-gray-50 dark:hover:bg-white/[0.02]', 'opacity-60' => ! $item->is_active])>
                            <td class="px-5 py-3">
                                <div class="flex items-start gap-3">
                                    @if ($item->image_path)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-md object-cover">
                                    @endif
                                    <div>
                                <span class="block text-gray-800 dark:text-white/90">{{ $item->description }}</span>
                                <span class="block text-theme-xs text-gray-500">
                                    per {{ $item->unit }}{{ $item->finish ? ' · '.ucfirst($item->finish) : '' }}{{ $item->business_line ? ' · '.$item->business_line->label().' only' : '' }}<span class="md:hidden"> · {{ $item->category }}</span>
                                    @unless ($item->is_active) · <span class="text-warning-700">hidden</span>@endunless
                                </span>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">{{ $item->category }}</td>
                            <td class="px-5 py-3 text-right tabular-nums {{ $item->rate_minor > 0 ? 'font-medium text-gray-800 dark:text-white/90' : 'text-gray-400' }}">
                                {{ $item->rate_minor > 0 ? Money::ofMinor((int) $item->rate_minor)->format($lakh) : 'Not set' }}
                            </td>
                            <td class="hidden px-5 py-3 text-right tabular-nums text-gray-500 lg:table-cell">
                                {{ $item->internal_cost_minor !== null ? Money::ofMinor((int) $item->internal_cost_minor)->format($lakh) : '—' }}
                            </td>
                            @if ($canEdit)
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <x-admin.button size="sm" variant="ghost" icon="pencil" wire:click="openEdit({{ $item->id }})" aria-label="Edit {{ $item->description }}" />
                                    <x-admin.button size="sm" variant="ghost" wire:click="toggleActive({{ $item->id }})">{{ $item->is_active ? 'Hide' : 'Show' }}</x-admin.button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-admin.empty icon="book" :title="$search !== '' || $category !== '' ? 'No items match' : 'The rate book is empty'"
                                               description="Add the items your team prices most often, with their unit and rate." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $items->links('components.admin.pagination') }}
    </x-admin.card>

    @if ($showForm)
        <x-admin.modal name="showForm" :title="$editingId ? 'Edit rate book item' : 'New rate book item'" submit="save" width="max-w-2xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Category" for="formCategory" class="sm:col-span-1">
                    <input id="formCategory" type="text" wire:model="formCategory" list="rate-book-categories" class="wx-input" placeholder="e.g. Wood work">
                    <datalist id="rate-book-categories">
                        @foreach ($categories as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                </x-admin.field>
                <x-admin.field label="Unit" for="formUnit">
                    <select id="formUnit" wire:model="formUnit" class="wx-input">
                        @foreach ($units as $value => $label)
                            <option value="{{ $value }}">{{ $value }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Description" for="formDescription" class="sm:col-span-2">
                    <textarea id="formDescription" wire:model="formDescription" rows="2" class="wx-input h-auto py-2.5"></textarea>
                </x-admin.field>
                <x-admin.field label="Rate (Rs per unit)" for="formRate" hint="Leave empty if it is priced per job.">
                    <input id="formRate" type="text" inputmode="decimal" wire:model="formRate" class="wx-input text-right tabular-nums" placeholder="0">
                </x-admin.field>
                <x-admin.field label="Internal cost (Rs per unit)" for="formCost" hint="Staff only; used for the margin.">
                    <input id="formCost" type="text" inputmode="decimal" wire:model="formCost" class="wx-input text-right tabular-nums" placeholder="—">
                </x-admin.field>
                <x-admin.field label="Finish level" for="formFinish">
                    <select id="formFinish" wire:model="formFinish" class="wx-input">
                        <option value="">Any</option>
                        @foreach ($finishes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Line" for="formLine">
                    <select id="formLine" wire:model="formLine" class="wx-input">
                        <option value="">All lines</option>
                        @foreach ($lines as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <label class="flex items-center gap-2 text-theme-sm sm:col-span-2">
                    <input type="checkbox" wire:model="formActive" class="size-4 rounded border-gray-300 text-navy-900"> Available in the quote builder
                </label>
                <x-admin.field label="Item picture" for="formImage" class="sm:col-span-2" hint="Shown on furniture quotations next to the description. JPG, PNG or WebP, up to 2 MB.">
                    <div class="flex items-center gap-4">
                        @if ($formImage)
                            <img src="{{ $formImage->temporaryUrl() }}" alt="New picture preview" class="size-16 rounded-md object-cover">
                        @elseif ($formImagePath)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($formImagePath) }}" alt="Current picture" class="size-16 rounded-md object-cover">
                        @endif
                        <input id="formImage" type="file" wire:model="formImage" accept="image/jpeg,image/png,image/webp" class="wx-input py-2">
                        <label class="flex items-center gap-2 text-theme-xs text-gray-500">
                            <input type="checkbox" wire:model="formRemoveImage" class="size-4 rounded border-gray-300 text-navy-900" @disabled($formImage !== null)> Remove picture
                        </label>
                    </div>
                    <p class="text-theme-xs text-gray-400">A picture is copied onto each quotation line that uses this item; later changes here never change sent quotations.</p>
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="check">Save item</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

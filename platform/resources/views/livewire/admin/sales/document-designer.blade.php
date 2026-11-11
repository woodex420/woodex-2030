<div>
    <x-admin.page-header title="Document design" description="Shared quotation and invoice identity for each brand. Saved styles are copied into new documents—not pushed into historical PDFs." :breadcrumbs="['Sales' => null, 'Document design' => null]" />
    <div class="mb-5 max-w-sm"><x-admin.field label="Brand" for="line"><select id="line" wire:model.live="line" class="wx-input"><option value="interior">Woodex Interior / Project</option><option value="furniture">Woodex Furniture</option></select></x-admin.field></div>
    <div class="grid gap-5 lg:grid-cols-2">
        <x-admin.card>
            <form wire:submit="save" class="space-y-4">
                <x-admin.field label="Brand name" for="form.brand_name"><input id="form.brand_name" wire:model="form.brand_name" class="wx-input" maxlength="35"></x-admin.field>
                <x-admin.field label="Accent color" for="form.accent_color" hint="Use a dark color with good contrast against white text."><input id="form.accent_color" type="color" wire:model="form.accent_color" class="h-11 w-24 rounded-lg border border-gray-200"></x-admin.field>
                <x-admin.field label="Header / tagline" for="form.header_text" hint="Empty uses the business tagline from Settings."><input id="form.header_text" wire:model="form.header_text" class="wx-input" maxlength="100"></x-admin.field>
                <x-admin.field label="Footer text" for="form.footer_text" hint="Empty keeps the standard legal/document footer."><input id="form.footer_text" wire:model="form.footer_text" class="wx-input" maxlength="120"></x-admin.field>
                <x-admin.field label="Logo" for="logo" hint="JPEG or PNG, maximum 96 KB and 600 × 240 pixels. Stored privately in the design and copied into document snapshots."><input id="logo" type="file" wire:model="logo" accept="image/png,image/jpeg" class="wx-input"></x-admin.field>
                <label class="flex items-center gap-2 text-theme-sm text-gray-600"><input type="checkbox" wire:model="removeLogo" class="wx-checkbox">Remove saved logo</label>
                @if ($line === 'furniture')
                    <fieldset class="space-y-3 rounded-lg border border-gray-200 p-3"><legend>Furniture green / grey sheet</legend>
                        <x-admin.field label="Secondary band colour" for="furnitureStyle.secondary_color"><input id="furnitureStyle.secondary_color" type="color" wire:model="furnitureStyle.secondary_color"></x-admin.field>
                        @foreach (['footer_align' => 'Footer alignment'] as $key => $label)
                            <x-admin.field :label="$label" :for="'furnitureStyle.'.$key"><select id="furnitureStyle.{{ $key }}" wire:model="furnitureStyle.{{ $key }}" class="wx-input">@foreach (['left', 'center', 'right'] as $align)<option value="{{ $align }}">{{ ucfirst($align) }}</option>@endforeach</select></x-admin.field>
                        @endforeach
                        @foreach (['quotation_title' => 'Default quotation heading', 'invoice_title' => 'Default invoice heading'] as $key => $label)
                            <x-admin.field :label="$label" :for="'furnitureStyle.'.$key"><input id="furnitureStyle.{{ $key }}" wire:model="furnitureStyle.{{ $key }}" maxlength="60" class="wx-input"></x-admin.field>
                        @endforeach
                        <x-admin.field label="Reference Woodex icon when no uploaded logo" for="furnitureStyle.use_default_logo"><select id="furnitureStyle.use_default_logo" wire:model="furnitureStyle.use_default_logo" class="wx-input"><option value="yes">Show reference icon</option><option value="no">No default icon</option></select></x-admin.field>
                        <p class="text-theme-xs text-gray-500">Brand name, primary accent, header/footer and uploaded logo above apply to both Furniture quotations and invoices. New Furniture documents use the premium A4 layout: logo/company left, metadata right, fixed header and footer. Per-document optional sections and centred signatories are edited in the quotation or invoice builder. Existing sent/issued documents remain unchanged.</p>
                    </fieldset>
                @endif
                <div class="border-t border-gray-200 pt-4">
                    <span class="wx-label">{{ $line === 'furniture' ? 'Compatibility v2.1 layouts only (not the green / grey Furniture sheet)' : 'PDF design' }}</span>
                    <div class="mt-2 space-y-2">
                        @foreach ($themes as $value => $label)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 {{ $pdfTheme === $value ? 'border-gray-900 dark:border-white' : 'border-gray-200' }}">
                                <input type="radio" wire:model.live="pdfTheme" value="{{ $value }}" class="mt-0.5">
                                <span>
                                    <span class="block text-theme-sm font-medium text-gray-900 dark:text-white">{{ $label }}</span>
                                    <span class="block text-theme-xs text-gray-500">{{ $themeDescriptions[$value] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('pdfTheme')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                </div>
                @foreach ([['kind' => 'quote', 'prop' => 'quoteBlocks', 'title' => 'Quotation PDF blocks', 'blocks' => $quoteBlocks, 'labels' => $quoteBlockLabels], ['kind' => 'invoice', 'prop' => 'invoiceBlocks', 'title' => 'Invoice PDF blocks', 'blocks' => $invoiceBlocks, 'labels' => $invoiceBlockLabels]] as $group)
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="wx-label">{{ $group['title'] }}</span>
                            <button type="button" wire:click="resetBlocks('{{ $group['kind'] }}')" class="text-theme-xs font-medium text-gray-500 hover:text-gray-900 dark:hover:text-white">Reset order</button>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500">{{ $group['kind'] === 'quote' ? 'Click Show / Hide for either brand. Furniture amounts and closing sections remain together above the final footer; arrows reorder within each section. Apply current design to an existing draft to adopt these defaults.' : 'Invoice items and totals are required; other invoice blocks can be hidden.' }}</p>
                        <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200">
                            @foreach ($group['blocks'] as $i => $block)
                                @php $locked = in_array($block['key'], $lockedBlocks[$group['kind']], true); @endphp
                                <li class="flex items-center gap-3 px-3 py-2" wire:key="{{ $group['kind'] }}-{{ $block['key'] }}">
                                    <span class="flex flex-col leading-none">
                                        <button type="button" wire:click="moveBlock('{{ $group['kind'] }}', {{ $i }}, -1)" @disabled($loop->first) class="px-1 text-gray-400 hover:text-gray-900 disabled:opacity-30 dark:hover:text-white" aria-label="Move {{ $group['labels'][$block['key']] }} up">↑</button>
                                        <button type="button" wire:click="moveBlock('{{ $group['kind'] }}', {{ $i }}, 1)" @disabled($loop->last) class="px-1 text-gray-400 hover:text-gray-900 disabled:opacity-30 dark:hover:text-white" aria-label="Move {{ $group['labels'][$block['key']] }} down">↓</button>
                                    </span>
                                    <span class="flex-1 text-theme-sm {{ $block['visible'] ? 'text-gray-900 dark:text-white' : 'text-gray-400 line-through' }}">{{ $group['labels'][$block['key']] }}</span>
                                    @if ($locked)
                                        <span class="text-theme-xs text-gray-400">Required for invoices</span>
                                    @else
                                        <button type="button" wire:click="toggleBlock('{{ $group['kind'] }}', {{ $i }})" aria-label="Toggle {{ $group['labels'][$block['key']] }}" aria-pressed="{{ $block['visible'] ? 'true' : 'false' }}" class="rounded-md border border-gray-200 px-3 py-1 text-theme-xs font-medium">{{ $block['visible'] ? 'Show' : 'Hide' }}</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <x-admin.button type="submit" wire:loading.attr="disabled" wire:target="save,logo">Save design</x-admin.button>
            </form>
        </x-admin.card>
        <x-admin.card>
            <h2 class="mb-4 font-medium text-gray-900 dark:text-white">Saved identity · revision {{ $design->revision }}</h2>
            @if ($design->logo_data_uri)<img src="{{ $design->logo_data_uri }}" alt="Saved brand logo" class="mb-3 max-h-16 max-w-full object-contain">@endif
            <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ $design->brand_name }}</p>
            <p class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">{{ $design->header_text ?: 'Business tagline from Settings' }}</p>
            <div class="my-5 border-t border-gray-200 pt-5 text-theme-sm text-gray-500">{{ $design->footer_text ?: 'Standard legal/document footer' }}</div>
            <p class="mt-3 text-theme-sm text-gray-500">PDF design: <strong class="text-gray-900 dark:text-white">{{ $themes[\App\Domain\Sales\Services\DocumentStyles::theme($design->pdf_theme ?? null)] }}</strong></p>
            <p class="mt-3 text-theme-sm text-gray-500">The card shows the saved identity, not a print proof. Create a new quote—or explicitly apply the current design to an editable draft—then use <strong>Preview PDF</strong> to check the real layout in this window.</p>
            <p class="mt-3 text-theme-xs text-gray-500">Business contact/address and payment details remain in their existing Settings screens. Photo columns and paper presets are planned separately.</p>
        </x-admin.card>
    </div>
</div>

@use('App\Support\Money')
@use('App\Support\NumberInput')
<div>
    <x-admin.page-header title="Quote templates" description="Start quotations from these. To change a template's sections and lines, build a quotation and use “Save as template”."
                         :breadcrumbs="['Sales' => null, 'Quote templates' => null]">
        <x-slot:actions>
            @if ($canCreateQuote)
                <x-admin.button icon="plus" :href="route('admin.quotes.create')" wire:navigate>New quotation</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <select wire:model.live="kind" class="wx-input w-auto" aria-label="Type">
            <option value="">All types</option>
            @foreach ($kinds as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-400">
            <input type="checkbox" wire:model.live="showInactive" class="size-4 rounded border-gray-300 text-navy-900"> Show hidden templates
        </label>
    </div>

    @if ($canEdit)
        <x-admin.card class="mb-4">
            <form wire:submit="importTemplate" class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <x-admin.field label="Import template file" for="importFile" hint="A .woodex-template.json exported from Woodex OS, or a v2.1 template pack (quotation-templates.json). Existing template names are skipped — nothing is overwritten.">
                        <input id="importFile" type="file" wire:model="importFile" accept=".json,application/json" class="wx-input">
                    </x-admin.field>
                </div>
                <x-admin.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="importTemplate,importFile">Import</x-admin.button>
            </form>
            @error('importFile')<p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p>@enderror
            <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                <x-admin.button variant="secondary" size="sm" icon="download" wire:click="importStarters" wire:loading.attr="disabled" wire:target="importStarters">Import Woodex starter templates</x-admin.button>
                <span class="text-theme-xs text-gray-500">Re-adds any missing standard templates (Renovation, Civil Work, Interior Designing, scopes…) with sections, lines, rates and terms — ones you edited stay untouched.</span>
            </div>
        </x-admin.card>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
        @forelse ($templates as $template)
            <x-admin.card wire:key="template-{{ $template->id }}" @class(['flex flex-col', 'opacity-60' => ! $template->is_active])>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium text-gray-900 dark:text-white">{{ $template->name }}</h2>
                        <p class="mt-0.5 text-theme-xs text-gray-500">
                            {{ $template->kind->label() }} · {{ $template->business_line->label() }} · {{ $template->milestone_plan->label() }}
                            @unless ($template->is_active) · <span class="text-warning-700">hidden</span>@endunless
                        </p>
                    </div>
                    <x-admin.badge color="gray" :dot="false">{{ $template->quotes_count }} {{ \Illuminate\Support\Str::plural('quote', $template->quotes_count) }}</x-admin.badge>
                </div>
                @if ($template->description)
                    <p class="mt-3 line-clamp-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $template->description }}</p>
                @endif
                <p class="mt-3 text-theme-xs text-gray-500">{{ $template->sectionCount() }} {{ \Illuminate\Support\Str::plural('section', $template->sectionCount()) }} · {{ $template->itemCount() }} {{ \Illuminate\Support\Str::plural('line', $template->itemCount()) }}</p>
                <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    @if ($canCreateQuote && $template->is_active)
                        <x-admin.button size="sm" icon="plus" :href="route('admin.quotes.create', ['template' => $template->id])" wire:navigate>Use</x-admin.button>
                    @endif
                    <x-admin.button size="sm" variant="secondary" wire:click="preview({{ $template->id }})">View lines</x-admin.button>
                    @if ($canEdit)
                        <x-admin.button size="sm" variant="ghost" icon="pencil" wire:click="openEdit({{ $template->id }})">Edit</x-admin.button>
                        <x-admin.button size="sm" variant="ghost" wire:click="exportTemplate({{ $template->id }})">Export</x-admin.button>
                        <x-admin.button size="sm" variant="ghost" wire:click="toggleActive({{ $template->id }})">{{ $template->is_active ? 'Hide' : 'Show' }}</x-admin.button>
                    @endif
                </div>
            </x-admin.card>
        @empty
            <x-admin.card class="md:col-span-2 2xl:col-span-3">
                <x-admin.empty icon="templates" title="No templates" description="Build a quotation, then use “Save as template” to reuse it." />
            </x-admin.card>
        @endforelse
    </div>

    @if ($previewTemplate)
        <x-admin.modal name="showPreview" :title="$previewTemplate->name" :description="$previewTemplate->sectionCount().' '.\Illuminate\Support\Str::plural('section', $previewTemplate->sectionCount()).' · '.$previewTemplate->itemCount().' '.\Illuminate\Support\Str::plural('line', $previewTemplate->itemCount()).'. Blank quantities and rates are filled in on the quotation.'" width="max-w-3xl">
            <div class="max-h-[60vh] space-y-5 overflow-y-auto">
                @foreach ($previewSections as $section)
                    <div>
                        <h3 class="text-theme-sm font-medium text-gray-900 dark:text-white">{{ $section['name'] }}</h3>
                        @if ($section['note'])<p class="text-theme-xs text-gray-500">{{ $section['note'] }}</p>@endif
                        <table class="mt-2 min-w-full text-theme-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($section['items'] as $item)
                                    <tr>
                                        <td class="py-1.5 pr-3 text-gray-700 dark:text-gray-300">{{ $item['description'] }}</td>
                                        <td class="py-1.5 pr-3 text-right whitespace-nowrap text-gray-500 tabular-nums">{{ $item['qty'] !== '0' ? NumberInput::fromQuantity($item['qty']).' '.$item['unit'] : '— '.$item['unit'] }}</td>
                                        <td class="py-1.5 text-right whitespace-nowrap tabular-nums {{ $item['rate_minor'] > 0 ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400' }}">{{ $item['rate_minor'] > 0 ? Money::ofMinor($item['rate_minor'])->format($lakh) : 'rate —' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showPreview', false)">Close</x-admin.button>
                @if ($canCreateQuote && $previewTemplate->is_active)
                    <x-admin.button icon="plus" :href="route('admin.quotes.create', ['template' => $previewTemplate->id])" wire:navigate>Use this template</x-admin.button>
                @endif
            </x-slot:footer>
        </x-admin.modal>
    @endif

    @if ($showForm)
        <x-admin.modal name="showForm" title="Edit template" submit="save" width="max-w-2xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-admin.field label="Name" for="formName" class="sm:col-span-3">
                    <input id="formName" type="text" wire:model="formName" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Description" for="formDescription" class="sm:col-span-3">
                    <input id="formDescription" type="text" wire:model="formDescription" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Type" for="formKind">
                    <select id="formKind" wire:model="formKind" class="wx-input">
                        @foreach ($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Line" for="formLine">
                    <select id="formLine" wire:model="formLine" class="wx-input">
                        @foreach ($lines as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Milestone plan" for="formPlan">
                    <select id="formPlan" wire:model="formPlan" class="wx-input">
                        @foreach ($plans as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Scope of work" for="formScope" class="sm:col-span-3" hint="Blank line = new paragraph; “-” at the start of a line = bullet.">
                    <textarea id="formScope" wire:model="formScope" rows="3" class="wx-input h-auto py-2.5"></textarea>
                </x-admin.field>
                <x-admin.field label="Terms and conditions" for="formTerms" class="sm:col-span-3">
                    <textarea id="formTerms" wire:model="formTerms" rows="5" class="wx-input h-auto py-2.5"></textarea>
                </x-admin.field>
                <x-admin.field label="Notes for the client" for="formNotes" class="sm:col-span-3">
                    <textarea id="formNotes" wire:model="formNotes" rows="2" class="wx-input h-auto py-2.5"></textarea>
                </x-admin.field>
                <label class="flex items-center gap-2 text-theme-sm sm:col-span-3">
                    <input type="checkbox" wire:model="formActive" class="size-4 rounded border-gray-300 text-navy-900"> Available for new quotations
                </label>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="check">Save template</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

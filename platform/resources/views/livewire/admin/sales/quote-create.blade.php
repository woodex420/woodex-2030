@use('App\Support\Phone')
@use('App\Support\Money')
<div>
    <x-admin.page-header title="New quotation" description="Choose the client and a template. You can change every line afterwards."
                         :breadcrumbs="['Sales' => null, 'Quotations' => route('admin.quotes.index'), 'New' => null]" />

    <form wire:submit="create" class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="min-w-0 space-y-6">
            <x-admin.card title="Choose your Woodex brand" description="Both brands share Sales; each has its own quotation template, logo and number series.">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach (['interior' => ['Woodex Interior', 'WI · interior design, execution and projects'], 'furniture' => ['Woodex Furniture', 'WF · products, specifications, advance and full invoices']] as $brand => $copy)
                        <button type="button" wire:click="chooseBrand('{{ $brand }}')" aria-pressed="{{ ($line === 'furniture') === ($brand === 'furniture') ? 'true' : 'false' }}" class="rounded-xl border p-4 text-left {{ ($line === 'furniture') === ($brand === 'furniture') ? 'border-navy-900 bg-navy-50' : 'border-gray-200' }}">
                            <strong class="block">{{ $copy[0] }}</strong><span class="mt-1 block text-theme-xs text-gray-500">{{ $copy[1] }}</span>
                        </button>
                    @endforeach
                </div>
            </x-admin.card>
            {{-- 1. Client --}}
            <x-admin.card title="1. Client" description="Quotations belong to a contact; link the enquiry so the lead shows it.">
                @if ($contact)
                    <div class="flex items-start justify-between gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">{{ $contact->name }}</p>
                            <p class="text-theme-sm text-gray-500">{{ Phone::display($contact->phone) ?: 'No phone' }}{{ $contact->company ? ' · '.$contact->company->name : '' }}</p>
                        </div>
                        <x-admin.button size="sm" variant="ghost" wire:click="clearContact">Change</x-admin.button>
                    </div>

                    <fieldset class="mt-4">
                        <legend class="wx-label">Enquiry</legend>
                        <div class="space-y-2">
                            @foreach ($openLeads as $openLead)
                                <label wire:key="lead-{{ $openLead->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-theme-sm has-checked:border-navy-900 has-checked:bg-navy-50 dark:border-gray-800 dark:has-checked:border-white dark:has-checked:bg-white/10">
                                    <input type="radio" wire:model.live="leadId" value="{{ $openLead->id }}" class="size-4 text-navy-900">
                                    <span class="flex-1">{{ $openLead->ref }} · {{ $openLead->business_line->label() }}{{ $openLead->service ? ' · '.\Illuminate\Support\Str::headline($openLead->service) : '' }}</span>
                                    <x-admin.badge :color="$openLead->stage->badgeColor()">{{ $openLead->stage->name }}</x-admin.badge>
                                </label>
                            @endforeach
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-theme-sm has-checked:border-navy-900 has-checked:bg-navy-50 dark:border-gray-800 dark:has-checked:border-white dark:has-checked:bg-white/10">
                                <input type="radio" wire:model.live="leadId" value="0" class="size-4 text-navy-900">
                                <span>Not linked to an open enquiry</span>
                            </label>
                        </div>
                        @error('leadId')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    </fieldset>
                @else
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                        <input type="search" wire:model.live.debounce.300ms="contactSearch" placeholder="Search clients by name or phone" class="wx-input pl-10" aria-label="Search clients" autofocus>
                    </div>
                    @error('contactId')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    @if (mb_strlen(trim($contactSearch)) >= 2)
                        <ul class="mt-3 divide-y divide-gray-100 rounded-xl border border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                            @forelse ($results as $result)
                                <li wire:key="result-{{ $result->id }}">
                                    <button type="button" wire:click="selectContact({{ $result->id }})" class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                                        <span>
                                            <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $result->name }}</span>
                                            <span class="block text-theme-xs text-gray-500">{{ Phone::display($result->phone) ?: 'No phone' }}{{ $result->company ? ' · '.$result->company->name : '' }}</span>
                                        </span>
                                        <x-icon name="chevron-right" class="size-4 text-gray-400" />
                                    </button>
                                </li>
                            @empty
                                <li class="px-4 py-3 text-theme-sm text-gray-500">No client found. New clients start as a lead: add them from <a href="{{ route('admin.leads.index') }}" wire:navigate class="font-medium text-navy-800 underline dark:text-white">Leads</a>.</li>
                            @endforelse
                        </ul>
                    @else
                        <p class="mt-2 text-theme-xs text-gray-500">Type at least 2 letters or digits.</p>
                    @endif
                @endif
            </x-admin.card>

            {{-- 2. Template --}}
            <x-admin.card title="2. Template" description="Sections, lines with today's rates, terms and the payment plan are copied into the draft.">
                @error('templateId')<p class="mb-3 text-theme-sm text-error-600">{{ $message }}</p>@enderror
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                    <button type="button" wire:click="chooseTemplate(0)"
                            @class(['flex flex-col rounded-xl border p-4 text-left transition', 'border-navy-900 bg-navy-50 ring-1 ring-navy-900 dark:border-white dark:bg-white/10 dark:ring-white' => $templateId === 0, 'border-dashed border-gray-300 hover:border-navy-300 dark:border-gray-700' => $templateId !== 0])>
                        <span class="font-medium text-gray-900 dark:text-white">Blank quotation</span>
                        <span class="mt-1 text-theme-xs text-gray-500">One empty section; add lines yourself or from the rate book.</span>
                    </button>
                    @foreach ($templates as $template)
                        <button type="button" wire:key="template-{{ $template->id }}" wire:click="chooseTemplate({{ $template->id }})"
                                @class(['flex flex-col rounded-xl border p-4 text-left transition', 'border-navy-900 bg-navy-50 ring-1 ring-navy-900 dark:border-white dark:bg-white/10 dark:ring-white' => $templateId === $template->id, 'border-gray-200 hover:border-navy-300 dark:border-gray-800' => $templateId !== $template->id])>
                            <span class="flex items-start justify-between gap-2">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $template->name }}</span>
                                <x-admin.badge :color="$template->kind->value === 'scope' ? 'wood' : ($template->kind->value === 'design' ? 'info' : 'navy')" :dot="false">{{ $template->kind->label() }}</x-admin.badge>
                            </span>
                            @if ($template->description)
                                <span class="mt-1 line-clamp-2 text-theme-xs text-gray-500">{{ $template->description }}</span>
                            @endif
                            <span class="mt-2 text-theme-xs text-gray-400">{{ $template->sectionCount() }} {{ \Illuminate\Support\Str::plural('section', $template->sectionCount()) }} · {{ $template->itemCount() }} {{ \Illuminate\Support\Str::plural('line', $template->itemCount()) }} · {{ $template->milestone_plan->label() }}{{ $template->business_line->value === 'furniture' ? ' · Furniture' : '' }}</span>
                        </button>
                    @endforeach
                </div>
            </x-admin.card>
        </div>

        {{-- 3. Details + create (stays in view while the templates scroll) --}}
        <div class="space-y-6 xl:sticky xl:top-24 xl:self-start">
            <x-admin.card title="3. Details">
                <div class="space-y-4">
                    <x-admin.field label="Line" for="line" hint="Interior and project quotes are numbered WI-…, furniture WF-….">
                        <select id="line" wire:model.live="line" class="wx-input">
                            @foreach ($lines as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field label="Title (optional)" for="title" hint="Defaults to the template name, e.g. “Renovation”.">
                        <input id="title" type="text" wire:model="title" class="wx-input" placeholder="e.g. Renovation of DHA Phase 6 house">
                    </x-admin.field>
                </div>
            </x-admin.card>
            <x-admin.button type="submit" icon="check" class="w-full" wire:loading.attr="disabled" wire:target="create">Create draft quotation</x-admin.button>
            <p class="text-center text-theme-xs text-gray-500">Drafts are not sent to anyone. You can delete a draft until it is sent.</p>
        </div>
    </form>
</div>

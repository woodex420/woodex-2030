@use('App\Support\Phone')
@use('Illuminate\Support\Str')
@php
    $me = auth()->user();
    $canUpdate = $me->can('update', $contact);
    $wa = Phone::whatsappUrl($contact->whatsappNumber(), "Assalam o Alaikum {$contact->firstName()}, this is {$me->name} from Woodex Interior.");
    $initials = Str::of($contact->name)->explode(' ')->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
@endphp
<div>
    <x-admin.page-header :title="$contact->name" :breadcrumbs="['CRM' => null, 'Contacts' => route('admin.contacts.index'), $contact->name => null]">
        <x-slot:actions>
            @can('invoices.view')
                <a href="{{ route('admin.contacts.statement', $contact) }}" target="_blank" rel="noopener"><x-admin.button variant="secondary" icon="document">Statement</x-admin.button></a>
            @endcan
            @if ($contact->phone)
                <x-admin.button variant="secondary" icon="phone" :href="Phone::telUrl($contact->phone)">Call</x-admin.button>
            @endif
            @if ($wa)
                <x-admin.button variant="secondary" icon="whatsapp" :href="$wa" target="_blank" rel="noopener" class="text-success-700">WhatsApp</x-admin.button>
            @endif
            @can('create', \App\Domain\Crm\Models\Lead::class)
                <x-admin.button icon="plus" wire:click="$set('showLead', true)">New enquiry</x-admin.button>
            @endcan
            @can('leads.delete')
                <x-admin.button variant="secondary" icon="trash" wire:click="deleteContact" wire:confirm="Move {{ $contact->name }} and all their enquiries to the recycle bin? They can be restored." aria-label="Delete contact" />
            @endcan
            @if (! $contact->anonymised_at)
                <a href="{{ route('admin.contacts.data', $contact) }}" target="_blank" rel="noopener"><x-admin.button variant="secondary" icon="file">Export data</x-admin.button></a>
                @can('leads.delete')
                    <x-admin.button variant="secondary" icon="eye-slash" wire:click="anonymiseContact" wire:confirm="Anonymise {{ $contact->name }}? Their name, numbers, addresses and message texts are erased for good — the row and the money records stay. This cannot be undone." aria-label="Anonymise contact" />
                @endcan
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($contact->anonymised_at)
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-warning-700 dark:bg-warning-500/10">
            <x-icon name="eye-slash" class="mt-0.5 size-5 shrink-0" />
            <p>Anonymised {{ $contact->anonymised_at->timezone('Asia/Karachi')->format('j M Y') }} at the person's request — personal fields and message texts are gone. The row stays for the books.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Profile --}}
        <div class="space-y-6">
            <x-admin.card>
                <div class="flex items-start gap-4">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-navy-900 font-heading text-theme-xl text-white">{{ $initials }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-theme-sm text-gray-500">{{ $contact->company?->name ?? $contact->client_type->label() }}</p>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @if ($contact->is_client)
                                <x-admin.badge color="success">Client{{ $contact->became_client_at ? ' since '.$contact->became_client_at->format('M Y') : '' }}</x-admin.badge>
                            @else
                                <x-admin.badge color="info">Prospect</x-admin.badge>
                            @endif
                            @if ($contact->opted_out_at)
                                <x-admin.badge color="error">Opted out of messages</x-admin.badge>
                            @endif
                        </div>
                    </div>
                    @if ($canUpdate)
                        <x-admin.button size="sm" variant="ghost" icon="pencil" wire:click="openEdit" aria-label="Edit contact" />
                    @endif
                </div>

                <dl class="mt-5 space-y-3 text-theme-sm">
                    @foreach ([
                        ['phone', 'Phone', $contact->phone ? Phone::display($contact->phone) : null, Phone::telUrl($contact->phone)],
                        ['phone', 'Alternate', $contact->alt_phone ? Phone::display($contact->alt_phone) : null, Phone::telUrl($contact->alt_phone)],
                        ['whatsapp', 'WhatsApp', $contact->whatsapp && $contact->whatsapp !== $contact->phone ? Phone::display($contact->whatsapp) : null, $wa],
                        ['mail', 'Email', $contact->email, $contact->email ? 'mailto:'.$contact->email : null],
                        ['map-pin', 'Address', trim(collect([$contact->address, $contact->area, $contact->city])->filter()->implode(', ')) ?: null, null],
                    ] as [$icon, $label, $text, $href])
                        @if ($text)
                            <div class="flex items-start gap-3">
                                <x-icon :name="$icon" class="mt-0.5 size-4 shrink-0 text-gray-400" />
                                <div class="min-w-0">
                                    <dt class="text-theme-xs text-gray-400">{{ $label }}</dt>
                                    <dd class="break-words text-gray-800 dark:text-white/90">
                                        @if ($href)<a href="{{ $href }}" class="hover:underline" @if (str_starts_with($href, 'https')) target="_blank" rel="noopener" @endif>{{ $text }}</a>@else{{ $text }}@endif
                                    </dd>
                                </div>
                            </div>
                        @endif
                    @endforeach
                    <div class="flex items-start gap-3">
                        <x-icon name="user" class="mt-0.5 size-4 shrink-0 text-gray-400" />
                        <div>
                            <dt class="text-theme-xs text-gray-400">Preferences</dt>
                            <dd class="text-gray-800 dark:text-white/90">{{ $contact->preferred_language === 'ur' ? 'Urdu' : 'English' }} · WhatsApp {{ $contact->consent_whatsapp ? 'allowed' : 'not confirmed' }} · Marketing {{ $contact->consent_marketing ? 'yes' : 'no' }}</dd>
                        </div>
                    </div>
                </dl>

                @if ($contact->notes)
                    <div class="mt-5 rounded-xl bg-gray-50 p-3 text-theme-sm whitespace-pre-line text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $contact->notes }}</div>
                @endif
            </x-admin.card>

            {{-- Tags --}}
            <x-admin.card title="Tags">
                <div class="flex flex-wrap gap-1.5">
                    @forelse ($contact->tags as $tag)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 py-0.5 pr-1 pl-2.5 text-theme-xs font-medium text-gray-700 dark:bg-white/5 dark:text-gray-300" wire:key="tag-{{ $tag->id }}">
                            {{ $tag->name }}
                            @if ($canUpdate)
                                <button type="button" wire:click="removeTag({{ $tag->id }})" class="rounded-full p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-white/10" aria-label="Remove {{ $tag->name }}"><x-icon name="x" class="size-3" /></button>
                            @endif
                        </span>
                    @empty
                        <p class="text-theme-sm text-gray-500">No tags.</p>
                    @endforelse
                </div>
                @if ($canUpdate)
                    <form wire:submit="addTag" class="mt-3 flex gap-2">
                        <input type="text" wire:model="newTag" class="wx-input" placeholder="VIP, architect, DHA…" aria-label="New tag">
                        <x-admin.button type="submit" variant="secondary" icon="tag">Add</x-admin.button>
                    </form>
                    @error('newTag') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                @endif
            </x-admin.card>

            {{-- Files (GLB-006) --}}
            <x-admin.attachments :files="$files" :can-edit="$canUpdate" />

            {{-- Possible duplicates (CRM-012) --}}
            @if ($duplicates->isNotEmpty())
                <x-admin.card title="Possible duplicates" description="Same phone, email or name. Merging moves every enquiry, note and document here.">
                    <ul class="space-y-2">
                        @foreach ($duplicates as $duplicate)
                            <li class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-800" wire:key="dup-{{ $duplicate->id }}">
                                <div class="min-w-0 text-theme-sm">
                                    <a href="{{ route('admin.contacts.show', $duplicate) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $duplicate->name }}</a>
                                    <p class="truncate text-theme-xs text-gray-500">{{ Phone::display($duplicate->phone) ?: $duplicate->email }} · {{ $duplicate->leads_count }} {{ Str::plural('enquiry', $duplicate->leads_count) }}</p>
                                </div>
                                <x-admin.button size="sm" variant="secondary" icon="merge" wire:click="merge({{ $duplicate->id }})"
                                                wire:confirm="Merge {{ $duplicate->name }} into {{ $contact->name }}? This cannot be undone.">Merge here</x-admin.button>
                            </li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif
        </div>

        {{-- Enquiries + timeline --}}
        <div class="space-y-6 xl:col-span-2">
            <x-admin.card title="Enquiries" :padding="false">
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($leads as $lead)
                        <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="flex flex-col gap-2 px-5 py-4 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-white/[0.02]" wire:key="lead-{{ $lead->id }}">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800 dark:text-white/90">{{ $lead->ref }} · {{ $lead->business_line->label() }}{{ $lead->service ? ' · '.Str::headline($lead->service) : '' }}</p>
                                <p class="text-theme-xs text-gray-500">{{ $lead->source->label() }} · {{ $lead->created_at?->format('j M Y') }} · {{ $lead->owner?->name ?? 'Unassigned' }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                @if ($lead->expectedValue())
                                    <span class="text-theme-sm text-gray-600 dark:text-gray-400">{{ $lead->expectedValue()->format(true) }}</span>
                                @endif
                                <x-admin.badge :color="$lead->stage->badgeColor()">{{ $lead->stage->name }}</x-admin.badge>
                            </div>
                        </a>
                    @empty
                        <x-admin.empty icon="users" title="No enquiries" description="Add a new enquiry when this contact asks for a quote." />
                    @endforelse
                </div>
            </x-admin.card>

            @if ($money)
                <x-admin.card title="Money" description="What this client has been quoted, invoiced and paid — summed from the real records.">
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-theme-xs text-gray-500">Awaiting decision</dt>
                            <dd class="mt-1 font-heading text-theme-xl text-gray-900 dark:text-white">{{ \App\Support\Money::ofMinor($money['awaiting'])->format($lakh) }}</dd>
                            <p class="text-theme-xs text-gray-400">Quotations sent, not yet answered</p>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-500">Invoiced</dt>
                            <dd class="mt-1 font-heading text-theme-xl text-gray-900 dark:text-white">{{ \App\Support\Money::ofMinor($money['invoiced'])->format($lakh) }}</dd>
                            <p class="text-theme-xs text-gray-400">Voided invoices excluded</p>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-500">Paid</dt>
                            <dd class="mt-1 font-heading text-theme-xl text-success-700 dark:text-success-500">{{ \App\Support\Money::ofMinor($money['paid'])->format($lakh) }}</dd>
                            <p class="text-theme-xs text-gray-400">{{ $money['last_payment'] ? 'Last payment '.\Illuminate\Support\Carbon::parse($money['last_payment'])->format('j M Y') : 'No payment recorded yet' }}</p>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-500">Outstanding</dt>
                            <dd class="mt-1 font-heading text-theme-xl {{ $money['outstanding'] > 0 ? 'text-warning-700 dark:text-warning-500' : 'text-gray-900 dark:text-white' }}">{{ \App\Support\Money::ofMinor($money['outstanding'])->format($lakh) }}</dd>
                            <p class="text-theme-xs text-gray-400">Issued and part-paid invoices</p>
                        </div>
                    </dl>

                    @if ($invoices->isNotEmpty())
                        <div class="mt-5 divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                            @foreach ($invoices as $invoice)
                                <a href="{{ route('admin.invoices.show', $invoice) }}" wire:navigate wire:key="money-inv-{{ $invoice->id }}"
                                   class="flex items-center justify-between gap-3 py-2.5 hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    <span class="min-w-0">
                                        <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $invoice->number }}</span>
                                        <span class="block text-theme-xs text-gray-500">Issued {{ $invoice->issue_date?->format('j M Y') ?? '—' }}{{ $invoice->due_date ? ' · due '.$invoice->due_date->format('j M Y') : '' }}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-3">
                                        <span class="text-theme-sm tabular-nums text-gray-700 dark:text-gray-300">{{ \App\Support\Money::ofMinor((int) $invoice->total_minor)->format($lakh) }}</span>
                                        <x-admin.badge :color="$invoice->status->color()">{{ $invoice->status->label() }}</x-admin.badge>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-admin.card>
            @endif

            @can('viewAny', \App\Domain\Sales\Models\Quote::class)
                <x-admin.card title="Quotations">
                    @can('create', \App\Domain\Sales\Models\Quote::class)
                        <x-slot:actions>
                            <x-admin.button size="sm" variant="secondary" icon="plus" :href="route('admin.quotes.create', ['contact' => $contact->id])" wire:navigate>New quotation</x-admin.button>
                        </x-slot:actions>
                    @endcan
                    <x-admin.quote-list :quotes="$quotes" :lakh="$lakh" />
                </x-admin.card>
            @endcan

            <x-admin.card title="Timeline" description="Everything across all enquiries.">
                <ol class="relative space-y-4 before:absolute before:top-2 before:bottom-2 before:left-4 before:w-px before:bg-gray-200 dark:before:bg-gray-800">
                    @forelse ($activities as $activity)
                        <li class="relative flex gap-3" wire:key="act-{{ $activity->id }}">
                            <span class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-gray-900 {{ $activity->type->tone() }}">
                                <x-icon :name="$activity->type->icon()" class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1 pt-1">
                                <p class="text-theme-sm text-gray-800 dark:text-white/90">
                                    <span class="font-medium">{{ $activity->type->label() }}</span>
                                    @if ($activity->lead)
                                        <a href="{{ route('admin.leads.show', $activity->lead_id) }}" wire:navigate class="ml-1 text-theme-xs text-gray-400 hover:underline">{{ $activity->lead->ref }}</a>
                                    @endif
                                </p>
                                @if ($activity->body)
                                    <p class="mt-0.5 text-theme-sm break-words whitespace-pre-line text-gray-600 dark:text-gray-400">{!! \App\Domain\Crm\Actions\NotifyMentions::highlight($activity->body) !!}</p>
                                @endif
                                <p class="mt-1 text-theme-xs text-gray-400">{{ $activity->user?->name ?? 'System' }} · {{ $activity->occurred_at->format('j M Y, g:i a') }}</p>
                            </div>
                        </li>
                    @empty
                        <li><x-admin.empty icon="activity" title="No activity yet" /></li>
                    @endforelse
                </ol>
            </x-admin.card>
        </div>
    </div>

    {{-- Edit --}}
    @if ($showEdit)
        <x-admin.modal name="showEdit" title="Edit contact" submit="save" width="max-w-2xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Name" for="form.name" class="sm:col-span-2">
                    <input id="form.name" type="text" wire:model="form.name" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Phone" for="form.phone">
                    <input id="form.phone" type="tel" wire:model="form.phone" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Alternate phone" for="form.alt_phone">
                    <input id="form.alt_phone" type="tel" wire:model="form.alt_phone" class="wx-input">
                </x-admin.field>
                <x-admin.field label="WhatsApp (if different)" for="form.whatsapp">
                    <input id="form.whatsapp" type="tel" wire:model="form.whatsapp" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Email" for="form.email">
                    <input id="form.email" type="email" wire:model="form.email" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Area / society" for="form.area">
                    <input id="form.area" type="text" wire:model="form.area" class="wx-input">
                </x-admin.field>
                <x-admin.field label="City" for="form.city">
                    <input id="form.city" type="text" wire:model="form.city" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Address" for="form.address" class="sm:col-span-2">
                    <input id="form.address" type="text" wire:model="form.address" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Type" for="form.client_type">
                    <select id="form.client_type" wire:model="form.client_type" class="wx-input">
                        @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Language" for="form.preferred_language">
                    <select id="form.preferred_language" wire:model="form.preferred_language" class="wx-input">
                        <option value="en">English</option>
                        <option value="ur">Urdu</option>
                    </select>
                </x-admin.field>
                <div class="flex flex-col gap-2 sm:col-span-2">
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="form.consent_whatsapp" class="rounded border-gray-300"> Agreed to WhatsApp messages</label>
                    <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="form.consent_marketing" class="rounded border-gray-300"> Agreed to marketing messages</label>
                </div>
                <x-admin.field label="Notes" for="form.notes" class="sm:col-span-2">
                    <textarea id="form.notes" wire:model="form.notes" rows="3" class="wx-input h-auto py-2.5"></textarea>
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showEdit', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Save</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- New enquiry --}}
    @if ($showLead)
        <x-admin.modal name="showLead" title="New enquiry for {{ $contact->name }}" description="If an open lead for the same line exists, the enquiry is added to it." submit="createLead">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Business line" for="leadLine">
                    <select id="leadLine" wire:model="leadLine" class="wx-input">
                        @foreach ($lines as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Service" for="leadService">
                    <input id="leadService" type="text" wire:model="leadService" class="wx-input" placeholder="kitchen, wardrobes, fit-out…">
                </x-admin.field>
                <x-admin.field label="What do they need?" for="leadMessage" class="sm:col-span-2">
                    <textarea id="leadMessage" wire:model="leadMessage" rows="3" class="wx-input h-auto py-2.5"></textarea>
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showLead', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Create lead</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

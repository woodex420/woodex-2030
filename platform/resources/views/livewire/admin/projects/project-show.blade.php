@use('App\Support\Money')
@use('App\Domain\Sales\Enums\InvoiceStatus')
<div>
    <x-admin.page-header :title="$project->code" :description="$project->name.' · '.$project->business_line->label()"
                         :breadcrumbs="['Projects' => route('admin.projects.index'), 'Board' => route('admin.projects.board'), $project->code => null]">
        <x-slot:actions>
            @if ($project->quote)
                <x-admin.button variant="secondary" icon="quote" :href="route('admin.quotes.show', $project->quote)" wire:navigate>Quotation {{ $project->quote->number }}</x-admin.button>
            @endif
            @can('invoices.view')
                <x-admin.button variant="secondary" icon="invoice" :href="route('admin.invoices.index', ['search' => $project->code])" wire:navigate>Invoices</x-admin.button>
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Where the job stands: the stage is what the client is told, the status is whether it is alive --}}
    <x-admin.card :padding="false" class="mb-4">
        <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-theme-xs text-gray-500" for="project-stage">Stage</label>
                <select id="project-stage" wire:change="setStage($event.target.value)" class="wx-input" @cannot('update', $project) disabled @endcannot>
                    @foreach ($stages as $stage)
                        <option value="{{ $stage->value }}" @selected($project->stage === $stage)>{{ $stage->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-theme-xs text-gray-500">{{ $project->stage->clientLine() }}</p>
            </div>
            <div>
                <label class="mb-1 block text-theme-xs text-gray-500" for="project-status">Status</label>
                <select id="project-status" wire:change="setStatus($event.target.value)" class="wx-input" @cannot('update', $project) disabled @endcannot>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($project->status === $status)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-theme-xs text-gray-500">
                    Started {{ $project->start_date?->format('j M Y') ?? '—' }}@if ($project->completed_at) · completed {{ $project->completed_at->format('j M Y') }}@endif
                </p>
            </div>
            <div>
                <label class="mb-1 block text-theme-xs text-gray-500" for="project-manager">Project manager</label>
                <input id="project-manager" type="text" class="wx-input" value="{{ $project->manager?->name ?? 'Unassigned' }}" disabled>
                <p class="mt-1 text-theme-xs text-gray-500">{{ $project->contact?->name }}@if ($project->city) · {{ $project->city }}@endif</p>
            </div>
            <div>
                <label class="mb-1 block text-theme-xs text-gray-500" for="project-target">Target</label>
                <input id="project-target" type="text" class="wx-input {{ $project->isOverdue() ? 'text-error-600' : '' }}"
                       value="{{ $project->target_date?->format('j M Y') ?? 'No target date' }}{{ $project->isOverdue() ? ' · late' : '' }}" disabled>
                <p class="mt-1 text-theme-xs text-gray-500">
                    {{ $project->site_address ? \Illuminate\Support\Str::limit($project->site_address, 40) : 'No site address' }}
                </p>
            </div>
        </div>

        {{-- The money: what the job is worth, what is asked for, what arrived, what is left --}}
        <div class="grid grid-cols-2 gap-px border-t border-gray-100 bg-gray-100 md:grid-cols-4 dark:border-gray-800 dark:bg-white/5">
            <div class="bg-white p-4 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500">Value</p>
                <p class="text-theme-lg font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $project->value_minor)->format($lakh) }}</p>
            </div>
            <div class="bg-white p-4 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500">Invoiced</p>
                <p class="text-theme-lg font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($project->invoicedMinor())->format($lakh) }}</p>
            </div>
            <div class="bg-white p-4 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500">Collected</p>
                <p class="text-theme-lg font-medium tabular-nums text-success-600">{{ Money::ofMinor($project->collectedMinor())->format($lakh) }}</p>
            </div>
            <div class="bg-white p-4 dark:bg-gray-900">
                <p class="text-theme-xs text-gray-500">Outstanding</p>
                <p class="text-theme-lg font-medium tabular-nums {{ $project->balanceMinor() > 0 ? 'text-warning-600' : 'text-gray-400' }}">{{ Money::ofMinor($project->balanceMinor())->format($lakh) }}</p>
            </div>
        </div>
    </x-admin.card>

    @can('accounts.view')
        <div class="mb-6"><livewire:admin.projects.project-costs :project-id="$project->id" :key="'project-costs-'.$project->id" /></div>
    @endcan

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Left: the work --}}
        <div class="space-y-4 lg:col-span-2">
            {{-- Milestones: what the money hangs on --}}
            <x-admin.card title="Milestones" :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($milestones as $milestone)
                        <li wire:key="milestone-{{ $milestone->id }}" class="flex items-center gap-3 px-4 py-3">
                            @can('update', $project)
                                <button type="button" wire:click="toggleMilestone({{ $milestone->id }})"
                                        class="flex size-5 shrink-0 items-center justify-center rounded-full border {{ $milestone->reached_at ? 'border-success-500 bg-success-500 text-white' : 'border-gray-300 hover:border-navy-500 dark:border-gray-600' }}"
                                        aria-pressed="{{ $milestone->reached_at ? 'true' : 'false' }}"
                                        title="{{ $milestone->reached_at ? 'Reopen this milestone' : 'Mark as reached' }}">
                                    @if ($milestone->reached_at)<x-icon name="check" class="size-3" />@endif
                                </button>
                            @else
                                <span class="flex size-5 shrink-0 items-center justify-center rounded-full border {{ $milestone->reached_at ? 'border-success-500 bg-success-500 text-white' : 'border-gray-300 dark:border-gray-600' }}">
                                    @if ($milestone->reached_at)<x-icon name="check" class="size-3" />@endif
                                </span>
                            @endcan

                            <div class="min-w-0 flex-1">
                                <p class="text-theme-sm font-medium {{ $milestone->reached_at ? 'text-gray-500 line-through' : 'text-gray-800 dark:text-white/90' }}">{{ $milestone->name }}</p>
                                <p class="text-theme-xs text-gray-500">
                                    {{ $milestone->percent_bp !== null ? rtrim(rtrim(number_format($milestone->percent_bp / 100, 2), '0'), '.').'%' : '—' }}
                                    @if ($milestone->reached_at) · reached {{ $milestone->reached_at->format('j M Y') }}@endif
                                    @if ($milestone->due_date && ! $milestone->reached_at) · {{ $milestone->due_date->lt(today()) ? 'overdue ' : '' }}{{ $milestone->due_date->format('j M Y') }}@endif
                                </p>
                            </div>

                            <span class="shrink-0 text-theme-sm tabular-nums text-gray-600 dark:text-gray-300">{{ Money::ofMinor((int) $milestone->amount_minor)->format($lakh) }}</span>

                            @php($invoice = $project->invoices->firstWhere('project_milestone_id', $milestone->id))
                            @if ($invoice)
                                <a href="{{ route('admin.invoices.index', ['search' => $invoice->number]) }}" wire:navigate
                                   class="shrink-0" title="Invoice {{ $invoice->number }}">
                                    <x-admin.badge :color="$invoice->status->color()">{{ $invoice->number }}</x-admin.badge>
                                </a>
                            @elseif ($milestone->reached_at && $milestone->amount_minor > 0)
                                @can('update', $project)
                                    <x-admin.button variant="ghost" size="sm" wire:click="raiseInvoice({{ $milestone->id }})">Raise invoice</x-admin.button>
                                @endcan
                            @endif
                        </li>
                    @empty
                        <li class="p-6 text-center text-theme-sm text-gray-500">This project has no milestones. The quotation's payment stages become milestones when it is accepted.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            {{-- Handover: what the studio leaves behind, ticked off and signed for (PRJ-007) --}}
            <x-admin.card title="Handover" :padding="false">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                        @if ($project->handoverItems->isEmpty())
                            Not started. The studio list is in Settings &rarr; Sales.
                        @else
                            <strong class="text-gray-800 dark:text-white/90">{{ $progress[0] }} of {{ $progress[1] }}</strong> done
                            @if ($project->client_ok_at) · signed by {{ $project->client_ok_name }} on {{ $project->client_ok_at->format('j M Y') }} @endif
                        @endif
                    </p>
                    @can('update', $project)
                        <div class="flex items-center gap-2">
                            @if ($project->handoverItems->isNotEmpty())
                                <x-admin.button variant="ghost" size="sm" wire:click="startHandover(true)" wire:confirm="Reset the checklist to the studio list? Ticks are lost.">Reset</x-admin.button>
                            @endif
                            <x-admin.button variant="secondary" size="sm" icon="check" wire:click="startHandover(false)">
                                {{ $project->handoverItems->isEmpty() ? 'Start checklist' : 'Add missing lines' }}
                            </x-admin.button>
                        </div>
                    @endcan
                </div>

                @if ($project->handoverItems->isNotEmpty())
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($project->handoverItems as $item)
                            <li wire:key="handover-{{ $item->id }}" class="flex items-center gap-3 px-4 py-2.5">
                                @can('update', $project)
                                    <button type="button" wire:click="tickHandoverItem({{ $item->id }})"
                                            class="flex size-5 shrink-0 items-center justify-center rounded-full border {{ $item->isDone() ? 'border-success-500 bg-success-500 text-white' : 'border-gray-300 hover:border-navy-500 dark:border-gray-600' }}"
                                            aria-pressed="{{ $item->isDone() ? 'true' : 'false' }}">
                                        @if ($item->isDone())<x-icon name="check" class="size-3" />@endif
                                    </button>
                                @else
                                    <span class="flex size-5 shrink-0 items-center justify-center rounded-full border {{ $item->isDone() ? 'border-success-500 bg-success-500 text-white' : 'border-gray-300 dark:border-gray-600' }}">
                                        @if ($item->isDone())<x-icon name="check" class="size-3" />@endif
                                    </span>
                                @endcan
                                <span class="min-w-0 flex-1 text-theme-sm {{ $item->isDone() ? 'text-gray-500 line-through' : 'text-gray-800 dark:text-white/90' }}">{{ $item->label }}</span>
                                @if ($item->isDone() && $item->doneBy)
                                    <span class="shrink-0 text-theme-xs text-gray-500">{{ $item->doneBy->name }}</span>
                                @endif
                                @can('update', $project)
                                    <button type="button" wire:click="removeHandoverItem({{ $item->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-error-50 hover:text-error-600" title="Remove">
                                        <x-icon name="trash" class="size-4" />
                                    </button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>

                    @can('update', $project)
                        <form wire:submit="addHandoverItem" class="flex items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                            <input type="text" wire:model="newItem" class="wx-input" placeholder="A line this project needs (e.g. AC vents cleaned)" aria-label="New checklist line">
                            <x-admin.button variant="secondary" size="sm" type="submit" icon="plus">Add</x-admin.button>
                        </form>
                        @error('newItem') <p class="px-4 pb-3 text-theme-xs text-error-600">{{ $message }}</p> @enderror

                        @if ($project->client_ok_at === null)
                            <div class="flex items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                                <input type="text" wire:model="signatureName" class="wx-input" placeholder="Client's name (signed on site)" aria-label="Client name">
                                <x-admin.button variant="primary" size="sm" icon="check" wire:click="recordHandoverSignature"
                                                wire:confirm="Record the client's signature for the handover?">Record signature</x-admin.button>
                            </div>
                        @endif
                    @endcan
                @endif
            </x-admin.card>

            {{-- The site diary --}}
            <x-admin.card title="Site diary" :padding="false">
                @can('update', $project)
                    <form wire:submit="post" class="border-b border-gray-100 p-4 dark:border-gray-800">
                        <label class="sr-only" for="update-body">What happened on site</label>
                        <textarea id="update-body" wire:model="body" rows="3" class="wx-input"
                                  placeholder="What happened on site today? (e.g. Wood work in the drawing room is complete, electrical rough-in starts tomorrow.)"></textarea>
                        @error('body') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror

                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            <div class="w-28">
                                <label class="mb-1 block text-theme-xs text-gray-500" for="update-progress">Progress %</label>
                                <input id="update-progress" type="number" min="0" max="100" wire:model="progressPercent" class="wx-input">
                                @error('progressPercent') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="min-w-40 flex-1">
                                <label class="mb-1 block text-theme-xs text-gray-500" for="update-milestone">Milestone (optional)</label>
                                <select id="update-milestone" wire:model="milestoneId" class="wx-input">
                                    <option value="">Not tied to a milestone</option>
                                    @foreach ($milestones as $milestone)
                                        <option value="{{ $milestone->id }}">{{ $milestone->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="flex cursor-pointer flex-col justify-center gap-1 rounded-xl border border-dashed border-gray-300 px-4 py-2 text-center hover:border-navy-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5">
                                <span class="flex items-center gap-1.5 text-theme-sm font-medium text-gray-700 dark:text-gray-300"><x-icon name="image" class="size-4" /> Photographs</span>
                                <span class="text-theme-xs text-gray-500">up to 10 · 15 MB each</span>
                                <input type="file" wire:model="photos" multiple accept="image/*" class="sr-only">
                            </label>
                        </div>

                        <div wire:loading wire:target="photos" class="mt-2 text-theme-xs text-gray-500">Uploading photographs…</div>
                        @error('photos.*') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror

                        @if (count($photos) > 0)
                            <p class="mt-2 text-theme-xs text-gray-500">{{ count($photos) }} photograph(s) attached.</p>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" wire:model="shareWithClient" class="size-4 rounded border-gray-300 text-navy-900 focus:ring-navy-500">
                                Share with the client
                            </label>
                            <div class="flex items-center gap-2">
                                <x-admin.button type="submit" icon="send">Post update</x-admin.button>
                            </div>
                        </div>
                    </form>
                @endcan

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($updates as $update)
                        <li wire:key="update-{{ $update->id }}" class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-theme-xs text-gray-500">
                                        {{ $update->author?->name ?? 'Woodex' }} · {{ $update->created_at?->format('D j M, g:i a') }}
                                        @if ($update->progress_percent !== null) · <span class="font-medium text-gray-600 dark:text-gray-300">{{ $update->progress_percent }}%</span> @endif
                                    </p>
                                    <p class="mt-1 text-theme-sm whitespace-pre-line text-gray-800 dark:text-white/90">{{ $update->body }}</p>

                                    @if ($update->milestone)
                                        <p class="mt-1 text-theme-xs text-gray-500">Milestone: {{ $update->milestone->name }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    @if ($update->client_visible)
                                        <x-admin.badge color="success">Shared {{ $update->shared_at?->format('j M') }}</x-admin.badge>
                                    @else
                                        <x-admin.badge color="gray" :dot="false">Internal</x-admin.badge>
                                        @can('update', $project)
                                            <x-admin.button variant="ghost" size="sm" icon="send" wire:click="share({{ $update->id }})"
                                                            wire:confirm="Share this update with the client?">Share</x-admin.button>
                                        @endcan
                                    @endif
                                    @can('update', $project)
                                        <button type="button" wire:click="deleteUpdate({{ $update->id }})" wire:confirm="Delete this update?"
                                                class="rounded-lg p-1.5 text-gray-400 hover:bg-error-50 hover:text-error-600" title="Delete">
                                            <x-icon name="trash" class="size-4" />
                                        </button>
                                    @endcan
                                </div>
                            </div>

                            @if ($update->getMedia('photos')->isNotEmpty())
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($update->getMedia('photos') as $photo)
                                        <a href="{{ route('admin.media.show', $photo) }}" target="_blank" rel="noopener"
                                           class="size-20 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5">
                                            <img src="{{ $photo->hasGeneratedConversion('thumb') ? route('admin.media.show', [$photo, 'thumb']) : route('admin.media.show', $photo) }}"
                                                 alt="{{ $photo->getCustomProperty('original_name', $photo->name) }}" width="80" height="80"
                                                 class="size-full object-cover" loading="lazy">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="p-6 text-center text-theme-sm text-gray-500">Nothing from the site yet. Post what happened today — with photographs — and share it when the client should see it.</li>
                    @endforelse
                </ul>
                {{ $updates->links('components.admin.pagination') }}
            </x-admin.card>
        </div>

        {{-- Right: the paperwork --}}
        <div class="space-y-4">
            {{-- The client's own page (PRJ-006): a signed link, or a code by email. No client password exists. --}}
            <x-admin.card title="Client portal">
                <label class="sr-only" for="portal-link">Client link</label>
                <div class="flex items-center gap-2">
                    <input id="portal-link" type="text" readonly value="{{ $portalUrl }}" class="wx-input text-theme-xs">
                    <button type="button" x-data @click="navigator.clipboard?.writeText(@js($portalUrl))"
                            class="shrink-0 rounded-lg border border-gray-200 px-2.5 py-2 text-gray-500 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5"
                            title="Copy the link"><x-icon name="copy" class="size-4" /></button>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <x-admin.button variant="secondary" size="sm" icon="whatsapp" :href="$whatsappShare" target="_blank">WhatsApp</x-admin.button>
                    @can('update', $project)
                        <x-admin.button variant="secondary" size="sm" icon="mail" wire:click="sendPortalLink">Email the link</x-admin.button>
                    @endcan
                </div>
                <p class="mt-2 text-theme-xs text-gray-500">If a client loses the link, they ask for a code at <span class="font-medium">/portal</span>.</p>

                @if ($project->client_ok_at && $project->review_requested_at === null && $reviewDueOn)
                    <p class="mt-3 text-theme-xs text-gray-500">Review request due {{ $reviewDueOn->format('j M Y') }} (<span class="font-medium">woodex:review-requests</span>, daily).</p>
                @elseif ($project->review_requested_at)
                    <p class="mt-3 text-theme-xs text-success-600">Review requested {{ $project->review_requested_at->format('j M Y') }}.</p>
                @endif
            </x-admin.card>

            {{-- With the client's consent, a finished job becomes a portfolio page for a person to write (PRJ-008) --}}
            <x-admin.card title="Case study">
                @can('update', $project)
                    <label class="flex items-start gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:click="toggleConsent({{ $project->case_study_consent ? 'false' : 'true' }})"
                               @checked($project->case_study_consent) class="mt-0.5 size-4 rounded border-gray-300 text-navy-900 focus:ring-navy-500">
                        <span>The client allows us to show this project on our website and in our portfolio.</span>
                    </label>
                @endcan

                @if ($project->caseStudyPage)
                    <p class="mt-3 text-theme-sm text-gray-700 dark:text-gray-300">
                        Draft page: <a href="{{ route('admin.website.pages.edit', $project->caseStudyPage) }}" wire:navigate class="font-medium text-navy-700 hover:underline">{{ $project->caseStudyPage->title }}</a>
                    </p>
                    <p class="mt-1 text-theme-xs text-gray-500">{{ $project->caseStudyPage->status->label() }} · {{ $project->caseStudyPage->slug }}</p>
                @endif

                @can('update', $project)
                    @can('website.edit')
                        <div class="mt-3">
                            <x-admin.button variant="secondary" size="sm" icon="image" wire:click="draftCaseStudy">
                                {{ $project->caseStudyPage ? 'Refresh the draft' : 'Create draft page' }}
                            </x-admin.button>
                        </div>
                    @endcan
                @endcan
            </x-admin.card>

            {{-- Invoices against this project --}}
            <x-admin.card title="Invoices ({{ $project->invoices->count() }})" :padding="false">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($project->invoices as $invoice)
                        <li wire:key="invoice-{{ $invoice->id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $invoice->number }}</p>
                                <p class="text-theme-xs text-gray-500">
                                    {{ $invoice->status->label() }}@if ($invoice->due_date) · due {{ $invoice->due_date->format('j M Y') }}@endif
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $invoice->total_minor)->format($lakh) }}</p>
                                <p class="text-theme-xs tabular-nums {{ $invoice->balance_minor > 0 ? 'text-warning-600' : 'text-success-600' }}">
                                    {{ $invoice->balance_minor > 0 ? 'due '.Money::ofMinor((int) $invoice->balance_minor)->format($lakh) : 'paid' }}
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="p-6 text-center text-theme-sm text-gray-500">No invoices yet. The advance one arrives with the acceptance.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            {{-- PRJ-009 + slice I: cost tracking — what it cost against what the quote said it would, and every
                 rupee of it booked (Dr the cost account / Cr accounts payable) the moment it is recorded. --}}
            <x-admin.card title="Legacy project cost history" description="Original project-expense records only; supplier/payment identity is unknown. Do not re-enter these as supplier bills or paid costs. Use linked Project costs for new purchases.">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                        <p class="text-theme-xs text-gray-500">Quote's internal cost</p>
                        <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">
                            {{ $quotedCostMinor !== null ? Money::ofMinor($quotedCostMinor)->format($lakh) : '—' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                        <p class="text-theme-xs text-gray-500">Legacy recorded total (not payment status)</p>
                        <p class="text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($spentMinor)->format($lakh) }}</p>
                    </div>
                    <div class="rounded-xl border px-4 py-3 {{ $overQuoted ? 'border-warning-500/40 bg-warning-50 dark:bg-warning-500/10' : 'border-gray-200 dark:border-gray-800' }}">
                        <p class="text-theme-xs text-gray-500">Quote less legacy costs only</p>
                        <p class="text-theme-sm font-medium tabular-nums {{ $marginMinor < 0 ? 'text-error-600' : 'text-gray-800 dark:text-white/90' }}">{{ Money::ofMinor($marginMinor)->format($lakh) }}</p>
                        @if ($overQuoted)
                            <p class="text-theme-xs text-warning-700">Spent is past the quote's internal cost.</p>
                        @endif
                    </div>
                </div>

                <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($expenses as $expense)
                        <li wire:key="expense-{{ $expense->id }}" class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $expense->description }}</p>
                                <p class="text-theme-xs text-gray-500">
                                    {{ $expense->category->label() }}@if ($expense->unit) · {{ rtrim(rtrim((string) $expense->qty, '0'), '.') }} {{ $expense->unit }}@endif · {{ $expense->incurred_on->format('j M Y') }}@if ($expense->author) · by {{ $expense->author->name }}@endif
                                    · @if ($expense->journal_entry_id) <span class="text-success-600">posted</span> @else <span class="text-gray-400">not posted</span> @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <p class="text-theme-sm tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($expense->amount_minor)->format($lakh) }}</p>
                                @can('update', $project)
                                    <button type="button" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Remove this expense line? Its journal entry is reversed, never deleted." class="text-gray-400 hover:text-error-600" aria-label="Remove expense"><x-icon name="x" class="size-4" /></button>
                                @endcan
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-theme-sm text-gray-500">No legacy project-expense records. Use linked Project costs for new supplier purchases.</li>
                    @endforelse
                </ul>

                @can('update', $project)
                    @cannot('accounts.manage')
                    <p class="mt-3 text-theme-xs text-gray-500">Legacy cost recording credits accounts payable but has no supplier/payment link. Ask finance to use linked costs for supplier purchases.</p>
                    <form wire:submit.prevent="addExpense" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-12">
                        <input type="text" wire:model="expenseDescription" placeholder="What was bought or paid" class="wx-input sm:col-span-4" aria-label="Description">
                        <select wire:model="expenseCategory" class="wx-input sm:col-span-2" aria-label="Category">
                            @foreach (\App\Domain\Projects\Enums\ProjectExpenseCategory::cases() as $category)
                                <option value="{{ $category->value }}">{{ $category->label() }}</option>
                            @endforeach
                        </select>
                        <input type="number" step="any" min="0" wire:model="expenseQty" placeholder="Qty" class="wx-input sm:col-span-1" aria-label="Quantity">
                        <input type="text" wire:model="expenseUnit" placeholder="Unit (sqft…)" class="wx-input sm:col-span-2" aria-label="Unit">
                        <input type="number" step="any" min="0" wire:model="expenseAmount" placeholder="Cost (Rs)" class="wx-input sm:col-span-2" aria-label="Cost in rupees">
                        <input type="date" wire:model="expenseIncurredOn" class="wx-input sm:col-span-1" aria-label="Date">
                        <div class="sm:col-span-12"><x-admin.button icon="plus">Record expense</x-admin.button></div>
                    </form>
                    @endcannot
                @endcan
            </x-admin.card>

            {{-- Files: drawings, approvals, photographs the team keeps --}}
            <x-admin.card title="Files ({{ $files->count() }})" :padding="false">
                @can('update', $project)
                    <div class="border-b border-gray-100 p-4 dark:border-gray-800">
                        <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-gray-300 px-4 py-5 text-center hover:border-navy-400 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5">
                            <x-icon name="upload" class="size-5 text-gray-400" />
                            <span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">Add drawings, approvals or documents</span>
                            <span class="text-theme-xs text-gray-500">JPG, PNG, PDF, DWG/DXF, Excel, Word · up to 20 MB each</span>
                            <input type="file" wire:model="uploads" multiple class="sr-only" accept=".{{ implode(',.', \App\Domain\Crm\Models\Lead::ATTACHMENT_EXTENSIONS) }}">
                        </label>
                        <div wire:loading wire:target="uploads" class="mt-2 text-theme-xs text-gray-500">Uploading…</div>
                        @error('uploads.*') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                @endcan
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($files as $file)
                        @php($isImage = str_starts_with((string) $file->mime_type, 'image/'))
                        <li wire:key="file-{{ $file->id }}" class="flex items-center gap-3 px-4 py-2.5">
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
                            @can('update', $project)
                                <button type="button" wire:click="toggleFileVisibility({{ $file->id }})"
                                        class="rounded-lg px-2 py-1 text-theme-xs font-medium {{ $file->getCustomProperty('visible_to_client') ? 'bg-success-50 text-success-700' : 'text-gray-400 hover:bg-gray-100' }}"
                                        title="{{ $file->getCustomProperty('visible_to_client') ? 'The client can see this on the portal — click to hide it' : 'Show this file to the client on the portal' }}">
                                    {{ $file->getCustomProperty('visible_to_client') ? 'On portal' : 'Private' }}
                                </button>
                                <button type="button" wire:click="deleteFile({{ $file->id }})" wire:confirm="Delete this file?" class="rounded-lg p-1.5 text-gray-400 hover:bg-error-50 hover:text-error-600" title="Delete"><x-icon name="trash" class="size-4" /></button>
                            @endcan
                        </li>
                    @empty
                        <li class="p-6 text-center text-theme-sm text-gray-500">No files yet. Drawings and approvals live here.</li>
                    @endforelse
                </ul>
            </x-admin.card>

            {{-- Internal notes --}}
            @can('update', $project)
                <x-admin.card title="Internal notes">
                    <label class="sr-only" for="project-notes">Notes</label>
                    <textarea id="project-notes" wire:model="notes" rows="5" class="wx-input" placeholder="Anything the team should know about this job."></textarea>
                    <div class="mt-3 flex justify-end">
                        <x-admin.button variant="secondary" icon="save" wire:click="saveDetails">Save notes</x-admin.button>
                    </div>
                </x-admin.card>
            @endcan
        </div>
    </div>
</div>

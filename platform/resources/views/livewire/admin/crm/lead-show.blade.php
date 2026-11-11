@use('App\Support\Phone')
@use('Illuminate\Support\Str')
@php
    $contact = $lead->contact;
    $me = auth()->user();
    $canUpdate = $me->can('update', $lead);
    $canAssign = $me->can('assign', \App\Domain\Crm\Models\Lead::class);
    $flow = $stages->whereNotIn('key', ['on_hold', 'lost'])->values();
    $current = $lead->stage;
    $wa = Phone::whatsappUrl($contact->whatsappNumber(), "Assalam o Alaikum {$contact->firstName()}, this is {$me->name} from Woodex Interior regarding your enquiry ({$lead->ref}).");
    $value = $lead->expectedValue()?->format($lakh);
    $isClosed = $current->isClosed();
@endphp
<div class="wx-workspace wx-lead-detail">
    {{-- Header --}}
    <x-admin.page-header :title="$contact->name" :breadcrumbs="['CRM' => null, 'Leads' => route('admin.leads.index'), $lead->ref => null]">
        <x-slot:actions>
            @if ($contact->phone)
                <x-admin.button variant="secondary" icon="phone" :href="Phone::telUrl($contact->phone)">Call</x-admin.button>
            @endif
            @if ($wa)
                <x-admin.button variant="secondary" icon="whatsapp" :href="$wa" target="_blank" rel="noopener" class="text-success-700">WhatsApp</x-admin.button>
            @endif
            @if ($canUpdate && ! $isClosed)
                <x-admin.button icon="calendar" wire:click="openVisit">Book site visit</x-admin.button>
            @endif
            @can('delete', $lead)
                <x-admin.button variant="secondary" icon="trash" wire:click="deleteLead" wire:confirm="Move {{ $lead->ref }} to the recycle bin? It can be restored." aria-label="Delete lead" />
            @endcan
        </x-slot:actions>
    </x-admin.page-header>

    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-2 text-theme-sm text-gray-500">
        <x-admin.badge :color="$current->badgeColor()">{{ $current->name }}</x-admin.badge>
        <x-admin.badge :color="$lead->priority->color()" :dot="false">{{ $lead->priority->label() }} priority</x-admin.badge>
        @if ($lead->isHot())
            <x-admin.badge color="wood" :dot="false"><x-icon name="flame" class="size-3.5" /> Hot · {{ $lead->score }}</x-admin.badge>
        @endif
        @if ($lead->first_response_at === null && $lead->sla_due_at && ! $isClosed)
            @if ($lead->isSlaBreached())
                <x-admin.badge color="error" :dot="false"><x-icon name="hourglass" class="size-3.5" /> Response overdue by {{ $lead->sla_due_at->diffForHumans(null, true) }}</x-admin.badge>
            @else
                <x-admin.badge color="warning" :dot="false"><x-icon name="hourglass" class="size-3.5" /> Respond {{ $lead->sla_due_at->diffForHumans() }}</x-admin.badge>
            @endif
        @endif
        <span class="text-gray-400">·</span>
        <span>{{ $lead->ref }}</span>
        <span class="text-gray-400">·</span>
        <span>{{ $lead->business_line->label() }}{{ $lead->service ? ' · '.Str::headline($lead->service) : '' }}</span>
        <span class="text-gray-400">·</span>
        <span>{{ $lead->source->label() }}, {{ $lead->created_at?->diffForHumans() }}</span>
    </div>

    {{-- Stage stepper (CRM-004) --}}
    <x-admin.card class="mb-6" :padding="false">
        <div class="flex flex-col gap-3 p-3 lg:flex-row lg:items-center">
            <ol class="grid flex-1 grid-cols-2 gap-1.5 sm:grid-cols-3 lg:flex lg:gap-1">
                @foreach ($flow as $stage)
                    @php
                        $done = ! in_array($current->key, ['lost', 'on_hold'], true) && $stage->position < $current->position;
                        $active = $stage->is($current);
                    @endphp
                    <li class="lg:flex-1">
                        <button type="button" @if ($canUpdate && ! $active) wire:click="moveTo('{{ $stage->key }}')" @endif @disabled(! $canUpdate || $active)
                                @class([
                                    'flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-theme-xs font-medium transition',
                                    'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $active,
                                    'bg-navy-50 text-navy-800 hover:bg-navy-100 dark:bg-white/10 dark:text-white' => $done,
                                    'bg-gray-50 text-gray-500 hover:bg-gray-100 dark:bg-white/[0.03] dark:text-gray-400' => ! $active && ! $done,
                                ])>
                            @if ($done)<x-icon name="check" class="size-3.5" />@endif
                            {{ $stage->name }}
                        </button>
                    </li>
                @endforeach
            </ol>
            @if ($canUpdate)
                <div class="flex gap-1.5">
                    <x-admin.button size="sm" variant="ghost" wire:click="moveTo('on_hold')" :disabled="$current->key === 'on_hold'">On hold</x-admin.button>
                    <x-admin.button size="sm" variant="ghost" class="text-error-600" wire:click="moveTo('lost')" :disabled="$current->key === 'lost'">Mark lost</x-admin.button>
                </div>
            @endif
        </div>
        @if ($current->key === 'lost' && $lead->lost_reason)
            <p class="border-t border-gray-100 px-5 py-3 text-theme-sm text-error-700 dark:border-gray-800">Lost: {{ $lead->lost_reason }}{{ $lead->lost_note ? ' — '.$lead->lost_note : '' }}</p>
        @endif
    </x-admin.card>

    <nav class="wx-section-nav mb-5 flex gap-1 overflow-x-auto" aria-label="Lead workspace sections"><a href="#lead-actions" class="wx-underline-tab">Overview & actions</a>@can('quotes.view')<a href="#lead-quotes" class="wx-underline-tab">Quotations</a>@endcan<a href="#activity-stream" class="wx-underline-tab">Activity</a><a href="#lead-tasks" class="wx-underline-tab">Tasks & details</a></nav>
    <div id="lead-actions" class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Main column --}}
        <div class="space-y-6 xl:col-span-2">
            @if ($canUpdate)
                {{-- Quick log (CRM-016): two taps for a call outcome --}}
                <x-admin.card title="Log activity" description="Calls, messages and notes go on the timeline. The first call or message counts as the response.">
                    <form wire:submit="logActivity" class="space-y-4">
                        <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Activity type">
                            @foreach (['call' => ['Call', 'phone'], 'whatsapp' => ['WhatsApp', 'whatsapp'], 'email' => ['Email', 'mail'], 'meeting' => ['Meeting', 'users'], 'note' => ['Note', 'note']] as $type => [$label, $icon])
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model="logType" value="{{ $type }}" class="peer sr-only">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-theme-sm text-gray-600 transition peer-checked:border-navy-900 peer-checked:bg-navy-900 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-navy-300 dark:border-gray-700 dark:text-gray-300 dark:peer-checked:border-white dark:peer-checked:bg-white dark:peer-checked:text-navy-900">
                                        <x-icon :name="$icon" class="size-4" /> {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div x-show="$wire.logType === 'call'" class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Call outcome">
                            @foreach ($callOutcomes as $value => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" wire:model="logOutcome" value="{{ $value }}" class="peer sr-only">
                                    <span @class([
                                        'inline-flex rounded-full border px-3 py-1 text-theme-xs font-medium transition peer-focus-visible:ring-2 peer-focus-visible:ring-navy-300',
                                        'border-success-200 text-success-700 peer-checked:bg-success-600 peer-checked:text-white peer-checked:border-success-600' => in_array($value, ['connected', 'call_back'], true),
                                        'border-gray-200 text-gray-600 peer-checked:bg-gray-800 peer-checked:text-white peer-checked:border-gray-800 dark:border-gray-700 dark:text-gray-300' => ! in_array($value, ['connected', 'call_back'], true),
                                    ])>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('logOutcome') <p class="text-theme-xs text-error-600">{{ $message }}</p> @enderror

                        <div>
                            <label for="logBody" class="sr-only">Details</label>
                            <textarea id="logBody" wire:model="logBody" rows="3" class="wx-input h-auto py-2.5"
                                      placeholder="What was discussed? Budget, timeline, decision makers… Type @name to notify a teammate."></textarea>
                            @error('logBody') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                            <p class="mt-1 flex items-center gap-1 text-theme-xs text-gray-400"><x-icon name="at-sign" class="size-3" /> @@Sara or @@sara.khan notifies them in the app and on Telegram.</p>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <p class="text-theme-xs text-gray-400">Tip: set the next action on the right after every contact.</p>
                            <x-admin.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="logActivity">Save</x-admin.button>
                        </div>
                    </form>
                </x-admin.card>
            @endif

            {{-- Estimations (P8.3): the enquiry's own priced record, linked both ways --}}
            @can('estimates.view')
                <x-admin.card title="Estimations">
                    <x-slot:actions>
                        @can('estimates.manage')
                            <x-admin.button size="sm" icon="calculator" :href="route('admin.estimations.create', ['lead' => $lead->id])">Start estimate</x-admin.button>
                        @endcan
                    </x-slot:actions>
                    @forelse ($estimates as $est)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-2.5 last:border-0 dark:border-gray-800">
                            <div>
                                <a href="{{ route('admin.estimations.show', $est) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $est->number }}</a>
                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ ucfirst($est->status->value) }} · {{ \App\Support\Money::ofMinor((int) ($est->computed['total_minor'] ?? 0))->format() }}</p>
                            </div>
                            @if ($est->quote !== null)
                                <a href="{{ route('admin.quotes.show', $est->quote) }}" class="text-theme-sm text-brand-600 hover:underline dark:text-brand-400">{{ $est->quote->number }}</a>
                            @endif
                        </div>
                    @empty
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">No estimate for this enquiry yet. Starting one fills the service and the size from the enquiry — check them, add the contingency, and the estimate is priced from the rate book.</p>
                    @endforelse
                </x-admin.card>
            @endcan

            {{-- Quotations (QUO-003/016) --}}
            @can('viewAny', \App\Domain\Sales\Models\Quote::class)
                <x-admin.card id="lead-quotes" title="Quotations">
                    @can('create', \App\Domain\Sales\Models\Quote::class)
                        @if ($current->key !== 'lost')
                            <x-slot:actions>
                                <x-admin.button size="sm" icon="plus" :href="route('admin.quotes.create', ['lead' => $lead->id])" wire:navigate>New quotation</x-admin.button>
                            </x-slot:actions>
                        @endif
                    @endcan
                    @if ($estimate)
                        <div class="mb-4 rounded-xl border border-navy-500/20 bg-navy-50/60 px-4 py-3 text-theme-sm dark:bg-white/5">
                            <p class="font-medium text-gray-800 dark:text-white/90">Website estimate: {{ $estimate['rate']->label }}, {{ number_format($estimate['area'], 0) }} {{ $estimate['rate']->unit }}, {{ ucfirst($estimate['finish']) }} finish</p>
                            @if ($estimate['estimate'] !== '')
                                <p class="mt-0.5 text-gray-500">They were shown {{ $estimate['estimate'] }}.</p>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <x-admin.button size="sm" icon="calculator" wire:click="startQuoteFromEstimate" wire:loading.attr="disabled">Start quotation from this estimate</x-admin.button>
                                <span class="text-theme-xs text-gray-400">Prices the line from the rate book as a draft — you check the scope before it goes out.</span>
                            </div>
                            @error('estimate')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <x-admin.quote-list :quotes="$quotes" :lakh="$lakh" empty="No quotation for this enquiry yet. Start one from a template once the site is measured." />
                </x-admin.card>
            @endcan

            {{-- Timeline (CRM-009) --}}
            <x-admin.card id="activity-stream" class="wx-activity-card" title="Activity" description="Recorded lead and shared contact history. Latest matching entries first.">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3"><span class="wx-eyebrow">{{ $activities->count() }} entries shown</span><select wire:model.live="activityType" class="wx-input w-auto" aria-label="Filter activity type"><option value="">All activity</option>@foreach (\App\Domain\Crm\Enums\ActivityType::cases() as $kind)<option value="{{ $kind->value }}">{{ $kind->label() }}</option>@endforeach</select></div>
                @php
                    $grouped = $activities->groupBy(fn ($a) => $a->occurred_at->isToday() ? 'Today' : ($a->occurred_at->isYesterday() ? 'Yesterday' : $a->occurred_at->format('D j M Y')));
                @endphp
                @forelse ($grouped as $day => $items)
                    <h3 class="mb-3 text-theme-xs font-medium tracking-wide text-gray-400 uppercase {{ $loop->first ? '' : 'mt-6' }}">{{ $day }}</h3>
                    <ol class="wx-activity-timeline relative space-y-4 before:absolute before:top-2 before:bottom-2 before:left-4 before:w-px before:bg-gray-200 dark:before:bg-gray-800">
                        @foreach ($items as $activity)
                            <li class="relative flex gap-3" wire:key="activity-{{ $activity->id }}">
                                <span class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-gray-900 {{ $activity->type->tone() }}">
                                    <x-icon :name="$activity->type->icon()" class="size-4" />
                                </span>
                                <div class="min-w-0 flex-1 pt-1">
                                    <p class="text-theme-sm text-gray-800 dark:text-white/90">
                                        <span class="font-medium">{{ $activity->type->label() }}</span>
                                        @if ($activity->outcome && $outcome = \App\Domain\Crm\Enums\CallOutcome::tryFrom($activity->outcome))
                                            <span @class(['ml-1 rounded-full px-2 py-0.5 text-theme-xs', 'bg-success-50 text-success-700' => $outcome->reached(), 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $outcome->reached()])>{{ $outcome->label() }}</span>
                                        @endif
                                        @if ($activity->direction === 'in')
                                            <span class="ml-1 text-theme-xs text-gray-400">incoming</span>
                                        @endif
                                    </p>
                                    @if ($activity->body)
                                        <p class="mt-0.5 text-theme-sm break-words whitespace-pre-line text-gray-600 dark:text-gray-400">{!! \App\Domain\Crm\Actions\NotifyMentions::highlight($activity->body) !!}</p>
                                    @endif
                                    <p class="mt-1 text-theme-xs text-gray-400">
                                        {{ $activity->user?->name ?? 'System' }} · <time datetime="{{ $activity->occurred_at->toIso8601String() }}" title="{{ $activity->occurred_at->format('j M Y, g:i a') }}">{{ $activity->occurred_at->format('g:i a') }}</time>
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @empty
                    <x-admin.empty icon="activity" title="No activity yet" description="Log the first call or WhatsApp message above." />
                @endforelse
                @if ($activities->count() >= min(600, max(60, $activityLimit)) && $activityLimit < 600)<button type="button" wire:click="loadMoreActivity" class="wx-history-button mt-5">Load earlier activity</button>@endif
            </x-admin.card>
        </div>

        {{-- Side column --}}
        <div class="space-y-6">
            {{-- Next action (CRM-006) --}}
            <x-admin.card title="Next action">
                @if ($lead->next_action_at && ! $isClosed)
                    <div @class(['mb-4 flex items-center gap-3 rounded-xl p-3', 'bg-error-50 text-error-700 dark:bg-error-500/10' => $lead->isOverdue(), 'bg-sand-100 text-navy-900 dark:bg-white/5 dark:text-white' => ! $lead->isOverdue()])>
                        <x-icon name="target" class="size-5 shrink-0" />
                        <div class="text-theme-sm">
                            <p class="font-medium">{{ $lead->next_action_type?->label() ?? 'Follow up' }} · {{ $lead->next_action_at->format('D j M, g:i a') }}</p>
                            <p class="text-theme-xs opacity-80">{{ $lead->isOverdue() ? 'Overdue by '.$lead->next_action_at->diffForHumans(null, true) : $lead->next_action_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @elseif (! $isClosed)
                    <p class="mb-4 rounded-xl bg-warning-50 p-3 text-theme-sm text-warning-700 dark:bg-warning-500/10">No next action. Every open lead needs one.</p>
                @endif

                @if ($canUpdate && ! $isClosed)
                    <form wire:submit="saveNextAction" class="space-y-3">
                        <div class="grid grid-cols-2 gap-2">
                            <select wire:model="nextType" class="wx-input" aria-label="Next action type">
                                @foreach ($nextTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="datetime-local" wire:model="nextAt" class="wx-input" aria-label="Due">
                        </div>
                        @error('nextAt') <p class="text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (['1h' => 'In 1 hour', 'tomorrow' => 'Tomorrow 11 am', '3d' => 'In 3 days', 'week' => 'Next week'] as $preset => $label)
                                <button type="button" wire:click="quickNext('{{ $preset }}')" class="rounded-full border border-gray-200 px-2.5 py-1 text-theme-xs text-gray-600 hover:border-navy-300 hover:text-navy-800 dark:border-gray-700 dark:text-gray-300">{{ $label }}</button>
                            @endforeach
                        </div>
                        <x-admin.button type="submit" variant="secondary" class="w-full">Save next action</x-admin.button>
                    </form>
                @endif
            </x-admin.card>

            {{-- Files (GLB-006) --}}
            <x-admin.attachments :files="$files" :can-edit="$canUpdate" />

            {{-- Owner --}}
            <x-admin.card title="Owner">
                @if ($canAssign)
                    <select class="wx-input" aria-label="Lead owner" wire:change="assignTo($event.target.value)">
                        <option value="" @selected($lead->owner_id === null)>Unassigned</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" @selected($lead->owner_id === $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                @else
                    <p class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $lead->owner?->name ?? 'Unassigned' }}</p>
                @endif
            </x-admin.card>

            {{-- Site visits (CRM-008) --}}
            <x-admin.card title="Site visits">
                @if ($canUpdate && ! $isClosed)
                    <x-slot:actions>
                        <x-admin.button size="sm" variant="secondary" icon="plus" wire:click="openVisit">Book</x-admin.button>
                    </x-slot:actions>
                @endif
                @forelse ($visits as $visit)
                    <div class="flex items-start gap-3 py-2 {{ $loop->first ? '' : 'border-t border-gray-100 dark:border-gray-800' }}" wire:key="visit-{{ $visit->id }}">
                        <span class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-sand-100 text-navy-900 dark:bg-white/5 dark:text-white">
                            <span class="text-[10px] leading-none uppercase">{{ $visit->scheduled_at->format('M') }}</span>
                            <span class="text-theme-sm leading-tight font-semibold">{{ $visit->scheduled_at->format('j') }}</span>
                        </span>
                        <div class="min-w-0 flex-1 text-theme-sm">
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $visit->scheduled_at->format('D, g:i a') }} <x-admin.badge :color="$visit->status->color()" class="ml-1">{{ $visit->status->label() }}</x-admin.badge></p>
                            <p class="truncate text-theme-xs text-gray-500">{{ $visit->assignee?->name ?? 'Unassigned' }}{{ $visit->address ? ' · '.$visit->address : '' }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-theme-sm text-gray-500">No visits booked yet.</p>
                @endforelse
                @if ($visits->isNotEmpty())
                    <a href="{{ route('admin.tasks.index', ['tab' => 'visits']) }}" wire:navigate class="mt-3 inline-flex text-theme-xs font-medium text-navy-700 hover:underline dark:text-gray-300">Open visit agenda →</a>
                @endif
            </x-admin.card>

            {{-- Tasks (CRM-007) --}}
            <x-admin.card id="lead-tasks" title="Tasks">
                <ul class="space-y-1">
                    @foreach ($openTasks as $task)
                        <li class="flex items-start gap-2.5 rounded-lg px-1 py-1.5 hover:bg-gray-50 dark:hover:bg-white/[0.02]" wire:key="task-{{ $task->id }}">
                            <button type="button" @if ($canUpdate) wire:click="toggleTask({{ $task->id }})" @endif class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border border-gray-300 text-transparent hover:border-success-500 hover:text-success-500 dark:border-gray-600" aria-label="Complete task">
                                <x-icon name="check" class="size-3.5" />
                            </button>
                            <div class="min-w-0 text-theme-sm">
                                <p class="text-gray-800 dark:text-white/90">{{ $task->title }}</p>
                                @if ($task->due_at)
                                    <p @class(['text-theme-xs', 'text-error-600' => $task->due_at->isPast(), 'text-gray-500' => ! $task->due_at->isPast()])>Due {{ $task->due_at->format('D j M, g:i a') }}{{ $task->assignee ? ' · '.$task->assignee->name : '' }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                    @foreach ($doneTasks as $task)
                        <li class="flex items-start gap-2.5 px-1 py-1.5" wire:key="task-{{ $task->id }}">
                            <button type="button" @if ($canUpdate) wire:click="toggleTask({{ $task->id }})" @endif class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md bg-success-500 text-white" aria-label="Reopen task">
                                <x-icon name="check" class="size-3.5" />
                            </button>
                            <p class="text-theme-sm text-gray-400 line-through">{{ $task->title }}</p>
                        </li>
                    @endforeach
                </ul>
                @if ($openTasks->isEmpty() && $doneTasks->isEmpty())
                    <p class="text-theme-sm text-gray-500">No tasks.</p>
                @endif
                @if ($canUpdate)
                    <form wire:submit="addTask" class="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <input type="text" wire:model="taskTitle" class="wx-input" placeholder="Add a task…" aria-label="New task">
                        @error('taskTitle') <p class="text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        <div class="flex gap-2">
                            <input type="datetime-local" wire:model="taskDue" class="wx-input" aria-label="Task due">
                            <x-admin.button type="submit" variant="secondary" icon="plus" aria-label="Add task" />
                        </div>
                    </form>
                @endif
            </x-admin.card>

            {{-- Details --}}
            <x-admin.card title="Details">
                @if ($canUpdate)
                    <x-slot:actions>
                        <x-admin.button size="sm" variant="ghost" icon="pencil" wire:click="editDetails">Edit</x-admin.button>
                    </x-slot:actions>
                @endif
                <dl class="grid grid-cols-3 gap-x-3 gap-y-2.5 text-theme-sm">
                    @foreach ([
                        'Service' => $lead->service ? Str::headline($lead->service) : null,
                        'Property' => $lead->property_type ? Str::headline($lead->property_type) : null,
                        'Location' => trim(($lead->area ?? '').', '.($lead->city ?? ''), ', ') ?: null,
                        'Size' => $lead->area_sqft ? number_format($lead->area_sqft).' sq ft' : null,
                        'Budget' => $lead->budget_band ? ($budgetBands[$lead->budget_band] ?? $lead->budget_band) : null,
                        'Timeline' => $lead->timeline ? ($timelines[$lead->timeline] ?? $lead->timeline) : null,
                        'Value' => $value,
                        'Score' => $lead->score.' / 100',
                        'Referred by' => $lead->referrer_name,
                    ] as $label => $text)
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="col-span-2 text-gray-800 dark:text-white/90">{{ $text ?? '—' }}</dd>
                    @endforeach
                </dl>
                @if ($lead->message)
                    <div class="mt-4 rounded-xl bg-gray-50 p-3 text-theme-sm whitespace-pre-line text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $lead->message }}</div>
                @endif
            </x-admin.card>

            {{-- Contact --}}
            <x-admin.card title="Contact">
                <x-slot:actions>
                    <x-admin.button size="sm" variant="ghost" :href="route('admin.contacts.show', $contact)" wire:navigate>Open</x-admin.button>
                </x-slot:actions>
                <dl class="space-y-2 text-theme-sm">
                    @if ($contact->phone)
                        <div class="flex items-center gap-2"><x-icon name="phone" class="size-4 text-gray-400" /><a href="{{ Phone::telUrl($contact->phone) }}" class="text-gray-800 hover:underline dark:text-white/90">{{ Phone::display($contact->phone) }}</a></div>
                    @endif
                    @if ($contact->alt_phone)
                        <div class="flex items-center gap-2"><x-icon name="phone" class="size-4 text-gray-400" /><span class="text-gray-600 dark:text-gray-400">{{ Phone::display($contact->alt_phone) }} (alt)</span></div>
                    @endif
                    @if ($contact->email)
                        <div class="flex items-center gap-2"><x-icon name="mail" class="size-4 text-gray-400" /><a href="mailto:{{ $contact->email }}" class="truncate text-gray-800 hover:underline dark:text-white/90">{{ $contact->email }}</a></div>
                    @endif
                    @if ($contact->is_client)
                        <x-admin.badge color="success">Client since {{ $contact->became_client_at?->format('M Y') ?? 'before' }}</x-admin.badge>
                    @endif
                    @if ($contact->tags->isNotEmpty())
                        <div class="flex flex-wrap gap-1 pt-1">
                            @foreach ($contact->tags as $tag)
                                <x-admin.badge :dot="false">{{ $tag->name }}</x-admin.badge>
                            @endforeach
                        </div>
                    @endif
                </dl>
                @if ($otherLeads->isNotEmpty())
                    <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <p class="mb-2 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Other enquiries</p>
                        @foreach ($otherLeads as $other)
                            <a href="{{ route('admin.leads.show', $other) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-1.5 text-theme-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="text-gray-700 dark:text-gray-300">{{ $other->ref }} · {{ $other->business_line->label() }}</span>
                                <x-admin.badge :color="$other->stage->badgeColor()">{{ $other->stage->name }}</x-admin.badge>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-admin.card>

            {{-- Source & attribution (CAP-010) --}}
            @php $touch = $lead->attribution['first_touch'] ?? []; @endphp
            <x-admin.card title="Source">
                <dl class="grid grid-cols-3 gap-x-3 gap-y-2.5 text-theme-sm">
                    <dt class="text-gray-500">Channel</dt><dd class="col-span-2 text-gray-800 dark:text-white/90">{{ $lead->source->label() }}</dd>
                    @if ($lead->source_detail)
                        <dt class="text-gray-500">Detail</dt><dd class="col-span-2 break-words text-gray-800 dark:text-white/90">{{ $lead->source_detail }}</dd>
                    @endif
                    @foreach (['landing_page' => 'Landing page', 'referrer' => 'Referrer', 'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'Campaign'] as $key => $label)
                        @if (! empty($touch[$key]))
                            <dt class="text-gray-500">{{ $label }}</dt><dd class="col-span-2 break-all text-gray-800 dark:text-white/90">{{ $touch[$key] }}</dd>
                        @endif
                    @endforeach
                </dl>
            </x-admin.card>
        </div>
    </div>

    {{-- Reassign with reason (CRM-009) --}}
    @if ($showReassign)
        <x-admin.modal name="showReassign" title="Reassign this lead" submit="confirmReassign"
                       description="The reason is written to the timeline so the previous owner knows why.">
            <x-admin.field label="Reason" for="reassignReason">
                <input id="reassignReason" type="text" wire:model="reassignReason" class="wx-input" placeholder="On leave this week / better fit for a project lead / workload">
            </x-admin.field>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showReassign', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="users">Reassign</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Book site visit --}}
    @if ($showVisit)
        <x-admin.visit-modal :staff="$staff" :conflicts="$visitConflicts" />
    @endif

    {{-- Edit details --}}
    @if ($showDetails)
        <x-admin.modal name="showDetails" title="Lead details" submit="saveDetails" width="max-w-2xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Business line" for="details.business_line">
                    <select id="details.business_line" wire:model="details.business_line" class="wx-input">
                        @foreach ($lines as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Priority" for="details.priority">
                    <select id="details.priority" wire:model="details.priority" class="wx-input">
                        @foreach ($priorities as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Service" for="details.service">
                    <input id="details.service" type="text" wire:model="details.service" class="wx-input" placeholder="e.g. kitchen, fit-out, wardrobes">
                </x-admin.field>
                <x-admin.field label="Property type" for="details.property_type">
                    <input id="details.property_type" type="text" wire:model="details.property_type" class="wx-input" placeholder="house, apartment, office…">
                </x-admin.field>
                <x-admin.field label="Area / society" for="details.area">
                    <input id="details.area" type="text" wire:model="details.area" class="wx-input" placeholder="DHA Phase 6">
                </x-admin.field>
                <x-admin.field label="City" for="details.city">
                    <input id="details.city" type="text" wire:model="details.city" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Address" for="details.address" class="sm:col-span-2">
                    <input id="details.address" type="text" wire:model="details.address" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Size (sq ft)" for="details.area_sqft" hint="1 marla = 225 sq ft · 1 kanal = 4,500 sq ft">
                    <input id="details.area_sqft" type="number" min="1" wire:model="details.area_sqft" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Budget" for="details.budget_band">
                    <select id="details.budget_band" wire:model="details.budget_band" class="wx-input">
                        <option value="">Not known</option>
                        @foreach ($budgetBands as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Timeline" for="details.timeline">
                    <select id="details.timeline" wire:model="details.timeline" class="wx-input">
                        <option value="">Not known</option>
                        @foreach ($timelines as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Expected value (Rs)" for="details.expected_value">
                    <input id="details.expected_value" type="number" min="0" step="1000" wire:model="details.expected_value" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Referred by" for="details.referrer_name" class="sm:col-span-2">
                    <input id="details.referrer_name" type="text" wire:model="details.referrer_name" class="wx-input">
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showDetails', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Save details</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif

    {{-- Lost reason (CRM-004) --}}
    @if ($showLost)
        <x-admin.modal name="showLost" title="Why was this lead lost?" description="Lost reasons feed the funnel report." submit="confirmLost">
            <div class="space-y-2">
                @foreach ($lostReasons as $reason)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-theme-sm hover:bg-gray-50 has-[:checked]:border-navy-900 has-[:checked]:bg-navy-50 dark:border-gray-700 dark:hover:bg-white/5">
                        <input type="radio" wire:model="lostReason" value="{{ $reason }}" class="text-navy-900">
                        {{ $reason }}
                    </label>
                @endforeach
                @error('lostReason') <p class="text-theme-xs text-error-600">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showLost', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" variant="danger">Mark as lost</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

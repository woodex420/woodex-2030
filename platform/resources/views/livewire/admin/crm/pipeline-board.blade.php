<div>
    <x-admin.page-header title="Pipeline" description="Drag a card to move it. Site visit books the visit, Lost asks for a reason, Won needs an accepted quotation."
                         :breadcrumbs="['CRM' => null, 'Pipeline' => null]">
        <x-slot:actions>
            <select wire:model.live="line" class="wx-input w-auto" aria-label="Business line">
                <option value="">All lines</option>
                @foreach ($lines as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="owner" class="wx-input w-auto" aria-label="Owner">
                <option value="">Everyone</option>
                <option value="me">My leads</option>
            </select>
            <x-admin.button variant="secondary" icon="tasks" :href="route('admin.leads.index')" wire:navigate>List view</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="custom-scrollbar -mx-4 flex gap-4 overflow-x-auto px-4 pb-4 md:-mx-6 md:px-6">
        @foreach ($stages as $stage)
            @php($cards = $leadsByStage->get($stage->id, collect()))
            <section wire:key="stage-{{ $stage->id }}" class="flex w-72 shrink-0 flex-col rounded-2xl border border-gray-200 bg-gray-100/60 dark:border-gray-800 dark:bg-white/[0.02]">
                <header class="flex items-center justify-between px-4 pt-4 pb-3">
                    <div class="flex items-center gap-2">
                        <span @class([
                            'size-2 rounded-full',
                            'bg-success-500' => $stage->type === \App\Domain\Crm\Enums\StageType::Won,
                            'bg-error-500' => $stage->type === \App\Domain\Crm\Enums\StageType::Lost,
                            'bg-navy-500' => $stage->type === \App\Domain\Crm\Enums\StageType::Open,
                        ])></span>
                        <h2 class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $stage->name }}</h2>
                        <span class="rounded-full bg-white px-2 text-theme-xs text-gray-500 dark:bg-white/5">{{ $cards->count() }}</span>
                    </div>
                    @if ($totals[$stage->id])
                        <span class="text-theme-xs text-gray-500">{{ $totals[$stage->id] }}</span>
                    @endif
                </header>
                <div data-kanban-column="{{ $stage->key }}" class="flex min-h-24 flex-1 flex-col gap-2.5 px-3 pb-3">
                    @foreach ($cards as $lead)
                        <article wire:key="card-{{ $lead->id }}" data-lead-id="{{ $lead->id }}"
                                 class="cursor-grab rounded-xl border border-gray-200 bg-white p-3.5 shadow-theme-xs active:cursor-grabbing dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-start justify-between gap-2">
                                <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate draggable="false" class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $lead->contact->name }}</a>
                                @if ($lead->isHot())
                                    <x-admin.badge color="navy" :dot="false">Hot</x-admin.badge>
                                @endif
                            </div>
                            <p class="mt-0.5 text-theme-xs text-gray-500">{{ $lead->ref }} · {{ $lead->business_line->label() }}</p>
                            @if ($lead->service || $lead->area)
                                <p class="mt-2 text-theme-xs text-gray-600 dark:text-gray-400">
                                    {{ $lead->service ? \Illuminate\Support\Str::headline($lead->service) : '' }}{{ $lead->service && $lead->area ? ' · ' : '' }}{{ $lead->area }}
                                </p>
                            @endif
                            <div class="mt-3 flex items-center justify-between">
                                @if ($lead->next_action_at && $stage->type === \App\Domain\Crm\Enums\StageType::Open)
                                    <span @class(['text-theme-xs', 'font-medium text-error-600' => $lead->isOverdue(), 'text-gray-500' => ! $lead->isOverdue()])>
                                        {{ $lead->next_action_type?->label() ?? 'Follow up' }} · {{ $lead->next_action_at->diffForHumans(short: true) }}
                                    </span>
                                @else
                                    <span class="text-theme-xs text-gray-400">{{ $lead->updated_at?->diffForHumans(short: true) }}</span>
                                @endif
                                <span class="flex size-6 items-center justify-center rounded-full bg-navy-50 text-[10px] font-semibold text-navy-700 dark:bg-white/10 dark:text-white" title="{{ $lead->owner?->name ?? 'Unassigned' }}">
                                    {{ $lead->owner?->initials() ?? '—' }}
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    @if ($pendingLeadId)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="lost-title">
            <form wire:submit="confirmLost" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-theme-lg dark:bg-gray-900">
                <h2 id="lost-title" class="text-theme-xl font-medium text-gray-900 dark:text-white">Why was this lead lost?</h2>
                <p class="mt-1 text-theme-sm text-gray-500">The reason feeds the funnel report and the lost-lead nurture sequence.</p>
                <select wire:model="lostReason" class="wx-input mt-4" aria-label="Lost reason">
                    <option value="">Choose a reason…</option>
                    @foreach ($lostReasons as $reason)
                        <option value="{{ $reason }}">{{ $reason }}</option>
                    @endforeach
                </select>
                @error('lostReason') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                <div class="mt-6 flex justify-end gap-2">
                    <x-admin.button variant="secondary" wire:click="cancelLost">Cancel</x-admin.button>
                    <x-admin.button type="submit" variant="danger">Mark as lost</x-admin.button>
                </div>
            </form>
        </div>
    @endif

    @if ($showVisit)
        <x-admin.visit-modal :staff="$staff" :conflicts="$visitConflicts" />
    @endif
</div>

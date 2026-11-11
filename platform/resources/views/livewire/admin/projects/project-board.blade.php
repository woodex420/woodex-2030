@use('App\Support\Money')
<div>
    <x-admin.page-header title="Project board" description="Drag a card to move the work on. Dropping a job on Completed closes it and tells the client it is finished."
                         :breadcrumbs="['Projects' => null, 'Board' => null]">
        <x-slot:actions>
            <select wire:model.live="line" class="wx-input w-auto" aria-label="Business line">
                <option value="">All lines</option>
                @foreach ($lines as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="owner" class="wx-input w-auto" aria-label="Project manager">
                <option value="">Everyone</option>
                <option value="me">My projects</option>
            </select>
            <x-admin.button variant="secondary" icon="tasks" :href="route('admin.projects.index')" wire:navigate>List view</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="custom-scrollbar -mx-4 flex gap-4 overflow-x-auto px-4 pb-4 md:-mx-6 md:px-6">
        @foreach ($stages as $stage)
            @php($cards = $projectsByStage->get($stage->value, collect()))
            <section wire:key="stage-{{ $stage->value }}" class="flex w-72 shrink-0 flex-col rounded-2xl border border-gray-200 bg-gray-100/60 dark:border-gray-800 dark:bg-white/[0.02]">
                <header class="flex items-center justify-between px-4 pt-4 pb-3">
                    <div class="flex items-center gap-2">
                        <span @class([
                            'size-2 rounded-full',
                            'bg-success-500' => $stage === \App\Domain\Projects\Enums\ProjectStage::Completed || $stage === \App\Domain\Projects\Enums\ProjectStage::Handover,
                            'bg-warning-500' => $stage === \App\Domain\Projects\Enums\ProjectStage::Execution || $stage === \App\Domain\Projects\Enums\ProjectStage::Procurement || $stage === \App\Domain\Projects\Enums\ProjectStage::Finishing,
                            'bg-navy-500' => $stage === \App\Domain\Projects\Enums\ProjectStage::Planning || $stage === \App\Domain\Projects\Enums\ProjectStage::Design,
                        ])></span>
                        <h2 class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $stage->label() }}</h2>
                        <span class="rounded-full bg-white px-2 text-theme-xs text-gray-500 dark:bg-white/5">{{ $cards->count() }}</span>
                    </div>
                    <span class="text-theme-xs text-gray-500 tabular-nums">{{ Money::ofMinor((int) $cards->sum('value_minor'))->format($lakh) }}</span>
                </header>

                {{-- data-kanban-* tells admin.js which Livewire method a drop calls (see initKanban) --}}
                <div data-kanban-column="{{ $stage->value }}"
                     data-kanban-item="[data-project-id]"
                     data-kanban-id-attr="projectId"
                     data-kanban-method="moveProject"
                     data-kanban-group="projects"
                     class="flex min-h-24 flex-1 flex-col gap-2.5 px-3 pb-3">
                    @foreach ($cards as $project)
                        <article wire:key="card-{{ $project->id }}" data-project-id="{{ $project->id }}"
                                 class="cursor-grab rounded-xl border border-gray-200 bg-white p-3.5 shadow-theme-xs active:cursor-grabbing dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-start justify-between gap-2">
                                <a href="{{ route('admin.projects.show', $project) }}" wire:navigate draggable="false" class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $project->name }}</a>
                                <span class="shrink-0 text-theme-xs tabular-nums text-gray-500">{{ $project->progress_percent }}%</span>
                            </div>
                            <p class="mt-0.5 text-theme-xs text-gray-500">{{ $project->code }} · {{ $project->contact?->name }}</p>

                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $project->progress_percent }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-navy-700" style="width: {{ $project->progress_percent }}%"></div>
                            </div>

                            @php($next = $project->milestones->firstWhere('reached_at', null))
                            @if ($next)
                                <p class="mt-2 truncate text-theme-xs text-gray-600 dark:text-gray-400">Next: {{ $next->name }}</p>
                            @endif

                            <div class="mt-3 flex items-center justify-between">
                                <span @class(['text-theme-xs', 'font-medium text-error-600' => $project->isOverdue(), 'text-gray-500' => ! $project->isOverdue()])>
                                    {{ $project->target_date?->format('j M') ?? 'no target' }}@if ($project->isOverdue()) · late @endif
                                </span>
                                <span class="flex size-6 items-center justify-center rounded-full bg-navy-50 text-[10px] font-semibold text-navy-700 dark:bg-white/10 dark:text-white" title="{{ $project->manager?->name ?? 'Unassigned' }}">
                                    {{ $project->manager?->initials() ?? '—' }}
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>

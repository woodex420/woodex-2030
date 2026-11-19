@use('App\Support\Money')
<div>
    <x-admin.page-header title="Projects" description="Every job the business is running: where it stands, what is invoiced and what has been collected. A project appears the moment a client accepts a quotation."
                         :breadcrumbs="['Projects' => null, 'Projects' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="kanban" :href="route('admin.projects.board')" wire:navigate>Board</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Status chips: how many jobs are alive, on hold, finished, cancelled --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1 no-scrollbar" role="tablist" aria-label="Filter by status">
        @php
            $chip = fn (bool $active) => 'inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-theme-sm font-medium transition '
                .($active ? 'border-navy-900 bg-navy-900 text-white dark:border-white dark:bg-white dark:text-navy-900' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300');
        @endphp
        <button type="button" wire:click="$set('status', '')" class="{{ $chip($status === '') }}" role="tab" aria-selected="{{ $status === '' ? 'true' : 'false' }}">
            All <span class="tabular-nums opacity-70">{{ $total }}</span>
        </button>
        @foreach ($statuses as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')" class="{{ $chip($status === $option->value) }}" role="tab" aria-selected="{{ $status === $option->value ? 'true' : 'false' }}">
                {{ $option->label() }} <span class="tabular-nums opacity-70">{{ (int) ($counts[$option->value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-admin.table label="Projects" :rows="$projects">
        <x-slot:filters class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div class="relative md:col-span-2">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Project, client or quotation number" class="wx-input pl-10" aria-label="Search projects">
            </div>
            <select wire:model.live="stage" class="wx-input" aria-label="Filter by stage">
                <option value="">All stages</option>
                @foreach ($stages as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="owner" class="wx-input" aria-label="Filter by project manager">
                <option value="">Everyone</option>
                <option value="me">My projects</option>
                @foreach ($staff as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:head>
            <x-admin.table.heading>Project</x-admin.table.heading>
            <x-admin.table.heading class="hidden lg:table-cell">Client</x-admin.table.heading>
            <x-admin.table.heading>Stage</x-admin.table.heading>
            <x-admin.table.heading class="hidden sm:table-cell">Progress</x-admin.table.heading>
            <x-admin.table.heading class="hidden lg:table-cell">Next milestone</x-admin.table.heading>
            <x-admin.table.heading align="right">Value</x-admin.table.heading>
            <x-admin.table.heading align="right" class="hidden md:table-cell">Collected</x-admin.table.heading>
            <x-admin.table.heading>Target</x-admin.table.heading>
        </x-slot:head>

        @foreach ($projects as $project)
            @php($next = $project->nextMilestone())
            <x-admin.table.row wire:key="project-{{ $project->id }}">
                <x-admin.table.cell header>
                    <a href="{{ route('admin.projects.show', $project) }}" wire:navigate class="block font-medium text-gray-800 hover:underline dark:text-white/90">{{ $project->name }}</a>
                    <span class="block text-theme-xs text-gray-500">{{ implode(' · ', array_filter([$project->code, $project->business_line->tag()])) }}</span>
                    @if ($project->status !== \App\Domain\Projects\Enums\ProjectStatus::Active)
                        <span class="mt-1 inline-block"><x-admin.badge :color="$project->status->color()">{{ $project->status->label() }}</x-admin.badge></span>
                    @endif
                </x-admin.table.cell>
                <x-admin.table.cell class="hidden lg:table-cell">
                    <span class="block text-gray-700 dark:text-gray-300">{{ $project->contact?->name }}</span>
                    <span class="block text-theme-xs text-gray-500">{{ $project->area ? $project->area.', ' : '' }}{{ $project->city }}</span>
                </x-admin.table.cell>
                <x-admin.table.cell><x-admin.badge :color="$project->stage->color()">{{ $project->stage->label() }}</x-admin.badge></x-admin.table.cell>
                <x-admin.table.cell class="hidden sm:table-cell">
                    <div class="flex items-center gap-2">
                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $project->progress_percent }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full rounded-full bg-navy-700" style="width: {{ $project->progress_percent }}%"></div>
                        </div>
                        <span class="tabular-nums text-theme-xs text-gray-500">{{ $project->progress_percent }}%</span>
                    </div>
                </x-admin.table.cell>
                <x-admin.table.cell class="hidden max-w-[14rem] lg:table-cell">
                    <span class="block truncate text-gray-700 dark:text-gray-300">{{ $next?->name ?? '—' }}</span>
                    <span class="block text-theme-xs text-gray-500">{{ $next?->due_date?->format('j M Y') ?? 'no date' }}</span>
                </x-admin.table.cell>
                <x-admin.table.cell align="right" numeric class="text-gray-800 dark:text-white/90">{{ Money::ofMinor((int) $project->value_minor)->format($lakh) }}</x-admin.table.cell>
                <x-admin.table.cell align="right" class="hidden md:table-cell">
                    <span class="block tabular-nums text-gray-800 dark:text-white/90">{{ Money::ofMinor($project->collectedMinor())->format($lakh) }}</span>
                    <span class="block tabular-nums text-theme-xs text-gray-500">of {{ Money::ofMinor($project->invoicedMinor())->format($lakh) }}</span>
                </x-admin.table.cell>
                <x-admin.table.cell class="{{ $project->isOverdue() ? 'text-red-600 dark:text-red-400' : 'text-gray-500' }}">
                    {{ $project->target_date?->format('j M Y') ?? '—' }}@if ($project->isOverdue()) · late @endif
                </x-admin.table.cell>
            </x-admin.table.row>
        @endforeach

        <x-slot:empty>
            <x-admin.empty icon="hammer" :title="$search !== '' || $stage !== '' || $status !== 'active' ? 'No projects match' : 'No projects yet'"
                           description="A project appears here as soon as a client accepts a quotation." />
        </x-slot:empty>
    </x-admin.table>
</div>

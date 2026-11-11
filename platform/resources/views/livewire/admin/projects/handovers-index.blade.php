<div>
    <x-admin.page-header title="Handovers"
                         description="Jobs at finishing or handover, with the checklist progress and the client’s signature state. The checklist itself opens on the project."
                         :breadcrumbs="['Projects' => route('admin.projects.index'), 'Handovers' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="kanban" :href="route('admin.projects.board')" wire:navigate>Board</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card title="Waiting for a walkthrough or a signature" :description="$projects->isEmpty() ? null : $projects->count().' job'.($projects->count() === 1 ? '' : 's')">
        @forelse ($projects as $project)
            @php([$done, $total] = $project->handoverProgress())
            <div wire:key="handover-{{ $project->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-3 last:border-0 dark:border-gray-800">
                <div class="min-w-0">
                    <p class="font-medium text-gray-800 dark:text-white/90">
                        <a href="{{ route('admin.projects.show', $project) }}" wire:navigate class="hover:underline">{{ $project->code }} · {{ $project->name }}</a>
                    </p>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $project->contact?->name ?? 'No client on the job' }} · {{ $project->stage->label() }}
                        @if ($project->handover_started_at)
                            · started {{ $project->handover_started_at->format('j M') }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <div class="w-40">
                        <div class="mb-1 flex justify-between text-theme-xs text-gray-500 dark:text-gray-400">
                            <span>Checklist</span><span class="tabular-nums">{{ $done }}/{{ $total }}</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="h-full rounded-full bg-wood-500" style="width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%"></div>
                        </div>
                    </div>

                    @if ($project->isHandedOver())
                        <x-admin.badge color="success">Signed</x-admin.badge>
                    @elseif ($project->handover_started_at)
                        <x-admin.badge color="warning">In handover</x-admin.badge>
                    @else
                        <x-admin.badge color="navy">Not started</x-admin.badge>
                    @endif

                    <x-admin.button variant="secondary" size="sm" :href="route('admin.projects.show', $project)" wire:navigate>Open project</x-admin.button>
                </div>
            </div>
        @empty
            <div class="py-10 text-center">
                <p class="text-gray-500 dark:text-gray-400">No job is at handover yet.</p>
                <p class="mt-1 text-theme-xs text-gray-400">A job joins this list when it reaches finishing. The checklist and the client signature open from the project page.</p>
            </div>
        @endforelse
    </x-admin.card>
</div>

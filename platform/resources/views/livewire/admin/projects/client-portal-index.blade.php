<div>
    <x-admin.page-header title="Client portal"
                         description="Every job's client door in one list. The link is signed, needs no password and stays valid for 120 days; a client who lost the message can be sent a fresh one without opening the project."
                         :breadcrumbs="['Projects' => route('admin.projects.index'), 'Client portal' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="arrow-left" :href="route('admin.projects.index')" wire:navigate>All projects</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card title="Portal links" :description="$projects->isEmpty() ? null : $projects->count().' jobs · the link opens the client’s project page, timeline and shared files'">
        @forelse ($projects as $project)
            <div wire:key="portal-{{ $project->id }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-3 last:border-0 dark:border-gray-800">
                <div class="min-w-0">
                    <p class="font-medium text-gray-800 dark:text-white/90">
                        <a href="{{ route('admin.projects.show', $project) }}" wire:navigate class="hover:underline">{{ $project->code }} · {{ $project->name }}</a>
                    </p>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $project->contact?->name ?? 'No client on the job' }} ·
                        <span class="inline-flex items-center gap-1"><span class="size-1.5 rounded-full bg-gray-400"></span>{{ $project->stage->label() }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2" x-data>
                    <input type="text" readonly value="{{ $links[$project->id] }}" aria-label="Portal link for {{ $project->code }}"
                           class="wx-input w-72 text-theme-xs" onclick="this.select()">
                    <x-admin.button variant="secondary" size="sm" type="button" x-data
                                    @click="navigator.clipboard?.writeText(@js($links[$project->id])); $el.textContent = 'Copied'; setTimeout(() => $el.textContent = 'Copy', 1500)">
                        Copy
                    </x-admin.button>
                    <x-admin.button variant="secondary" size="sm" :href="$links[$project->id]" target="_blank" rel="noopener">Open</x-admin.button>
                    <x-admin.button variant="secondary" size="sm" :href="$shareable[$project->id]" target="_blank" rel="noopener" title="Send it on WhatsApp">WhatsApp</x-admin.button>
                </div>
            </div>
        @empty
            <div class="py-10 text-center">
                <p class="text-gray-500 dark:text-gray-400">No projects yet, so there is no portal to share.</p>
                <p class="mt-1 text-theme-xs text-gray-400">A project opens the moment a client accepts a quotation. Until then the quotation and invoice links do the same job.</p>
                <div class="mt-4 flex justify-center gap-2">
                    <a href="{{ route('admin.quotes.index') }}" wire:navigate><x-admin.button variant="secondary" icon="quote">Quotations</x-admin.button></a>
                    <a href="{{ route('admin.projects.index') }}" wire:navigate><x-admin.button icon="hammer">Projects</x-admin.button></a>
                </div>
            </div>
        @endforelse
    </x-admin.card>

    <p class="mt-4 text-theme-xs text-gray-500 dark:text-gray-400">
        Nothing is public by default: the link is a signed URL, revocable by letting it expire. The client sees the project, its timeline and only the files marked <span class="font-medium">On portal</span>.
    </p>
</div>

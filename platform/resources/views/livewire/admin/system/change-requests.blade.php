<div>
    <x-admin.page-header title="Change requests" description="Every /idea and /issue from Telegram, in one flow. Moving a card tells the person who asked."
                         :breadcrumbs="['System' => null, 'Change Requests' => null]" />

    <x-admin.card>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->filters() as $option)
                    <button type="button" wire:click="$set('filter', '{{ $option['value'] }}')"
                            class="rounded-lg px-3 py-1.5 text-theme-sm font-medium {{ $filter === $option['value'] ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' }}">
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search title or CR-0001…"
                   class="wx-input sm:max-w-xs" />
        </div>
    </x-admin.card>

    <x-admin.card :title="'Board ('.$this->requests()->total().')'" :padding="false" class="mt-6">
        @forelse ($this->requests() as $request)
            <div wire:key="cr-{{ $request->id }}" class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 last:border-0 lg:flex-row lg:items-start lg:justify-between dark:border-gray-800">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-heading text-theme-sm font-semibold text-gray-900 dark:text-white">{{ $request->reference() }}</span>
                        <x-admin.badge :color="$request->type->value === 'issue' || $request->type->value === 'bug' ? 'error' : 'info'">{{ $request->type->label() }}</x-admin.badge>
                        <x-admin.badge :color="in_array($request->status->value, ['done', 'rejected'], true) ? 'success' : 'warning'">{{ $request->status->label() }}</x-admin.badge>
                        @if ($request->source === 'telegram')<x-admin.badge color="gray">Telegram</x-admin.badge>@endif
                    </div>
                    <p class="mt-1 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $request->title }}</p>
                    @if ($request->body && $request->body !== $request->title)
                        <p class="mt-0.5 max-w-2xl text-theme-sm text-gray-500">{{ Str::limit($request->body, 220) }}</p>
                    @endif
                    <p class="mt-1 text-theme-xs text-gray-500">by {{ $request->requester?->name ?? '—' }} · {{ $request->created_at?->diffForHumans() }}@if ($request->phase) · phase {{ $request->phase }}@endif</p>
                    @if ($request->resolution_note)
                        <p class="mt-1 text-theme-sm text-gray-600 italic dark:text-gray-400">“{{ $request->resolution_note }}”</p>
                    @endif
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    @foreach ($this->nextStatuses($request) as $option)
                        <x-admin.button variant="secondary" wire:click="setStatus({{ $request->id }}, '{{ $option['value'] }}')" wire:loading.attr="disabled">{{ $option['label'] }}</x-admin.button>
                    @endforeach
                </div>
            </div>
        @empty
            <x-admin.empty icon="lightbulb" title="Nothing here" description="Ideas and issues sent with /idea and /issue from Telegram appear on this board." />
        @endforelse
        <div class="px-5 py-3">
            {{ $this->requests()->links() }}
        </div>
    </x-admin.card>

    @if ($showNote)
        <x-admin.modal name="showNote" title="Close this card" description="The requester is told the outcome and your note." submit="saveNote">
            <label for="cr-note" class="wx-label">Note</label>
            <textarea id="cr-note" wire:model="note" rows="3" class="wx-input h-auto py-2" placeholder="For example: fixed on the live site, or not worth the build time right now."></textarea>
            @error('note')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
            <x-slot:footer>
                <x-admin.button variant="secondary" type="button" wire:click="$set('showNote', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Save</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

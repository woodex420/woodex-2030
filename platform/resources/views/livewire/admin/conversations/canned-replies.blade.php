<div>
    <x-admin.page-header title="Canned replies" description="The words behind the “/” picker in the inbox. Variables: {first_name} {studio_name} {studio_hours} {quote_link} — filled in per client when picked."
                         :breadcrumbs="['System' => null, 'Canned replies' => null]">
        <x-slot:actions>
            <x-admin.button icon="plus" wire:click="openEditor">Add reply</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card :title="'Saved replies ('.$replies->count().')'" :padding="false">
        @forelse ($replies as $reply)
            <div wire:key="reply-{{ $reply->id }}" class="flex flex-col gap-2 border-b border-gray-100 px-5 py-4 last:border-0 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-gray-900 dark:text-white">{{ $reply->title }}</span>
                        @if ($reply->locale !== 'en')<x-admin.badge color="gray">{{ strtoupper($reply->locale) }}</x-admin.badge>@endif
                        @unless ($reply->is_active)<x-admin.badge color="warning">hidden</x-admin.badge>@endunless
                        <span class="text-theme-xs text-gray-400">used {{ $reply->usage_count }}×</span>
                    </div>
                    <p class="mt-1 max-w-3xl whitespace-pre-wrap text-theme-sm text-gray-600 dark:text-gray-400">{{ $reply->body }}</p>
                    @if ($reply->keywords !== '')
                        <p class="mt-0.5 text-theme-xs text-gray-400">matches: {{ $reply->keywords }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 gap-2">
                    <x-admin.button variant="secondary" wire:click="toggle({{ $reply->id }})">{{ $reply->is_active ? 'Hide' : 'Show' }}</x-admin.button>
                    <x-admin.button variant="secondary" wire:click="edit({{ $reply->id }})">Edit</x-admin.button>
                </div>
            </div>
        @empty
            <x-admin.empty icon="message" title="No saved replies yet" description="Add the answers the team types every day — welcome, hours, the quotation link." />
        @endforelse
    </x-admin.card>

    @if ($showEditor)
        <x-admin.modal name="showEditor" :title="$editingId !== null ? 'Edit saved reply' : 'Add saved reply'" description="English and Roman Urdu both belong here; pick what the client used." submit="save">
            <div class="space-y-3">
                <div>
                    <label for="canned-title" class="wx-label">Title (shown in the "/" picker)</label>
                    <input id="canned-title" type="text" wire:model="title" class="wx-input" placeholder="For example: Quotation link" />
                    @error('title')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="canned-body" class="wx-label">Message</label>
                    <textarea id="canned-body" wire:model="body" rows="4" class="wx-input h-auto py-2" placeholder="Assalam-o-alaikum {first_name}, here is your quotation: {quote_link}"></textarea>
                    @error('body')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    <p class="mt-1 text-theme-xs text-gray-500">Variables: {first_name} · {studio_name} · {studio_hours} · {quote_link}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="canned-locale" class="wx-label">Language</label>
                        <select id="canned-locale" wire:model="locale" class="wx-input">
                            <option value="en">English</option>
                            <option value="ur">Roman Urdu</option>
                        </select>
                    </div>
                    <div>
                        <label for="canned-keywords" class="wx-label">Also matches (optional)</label>
                        <input id="canned-keywords" type="text" wire:model="keywords" class="wx-input" placeholder="hours, timing, open" />
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <x-admin.button variant="secondary" type="button" wire:click="$set('showEditor', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">{{ $editingId !== null ? 'Save' : 'Add' }}</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

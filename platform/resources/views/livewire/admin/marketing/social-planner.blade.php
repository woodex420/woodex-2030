<div>
    <x-admin.page-header title="Social planner" description="One composition — caption in English and Urdu, photographs from the media library — headed for several networks. An editor's post needs someone else's approval; scheduling waits for its time, and for a connected account until the Meta/LinkedIn approvals land."
                         :breadcrumbs="['Marketing' => null, 'Social planner' => null]" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-admin.button icon="plus" wire:click="compose">Compose</x-admin.button>
        <span class="rounded-full bg-soft px-3 py-1 text-theme-xs {{ $connected !== [] ? 'text-success-700' : 'text-gray-500' }}">
            {{ $connected !== [] ? 'Connected: '.implode(', ', $connected) : 'No social account connected yet — posts compose, review and schedule; publishing waits for the approvals' }}
        </span>
    </div>

    @if ($showCompose)
        <x-admin.card class="mb-4" title="Compose" description="Write it once; each network gets the same words. The first comment and hashtags ride along.">
            <form wire:submit="saveDraft" class="space-y-4">
                <x-admin.field label="Caption (English)" for="text">
                    <textarea id="text" rows="3" wire:model="text" class="wx-input" placeholder="A ten-marla home in DHA, handed over on the date we promised."></textarea>
                </x-admin.field>

                @if ($aiReady)
                    <button type="button" wire:click="draftCaptions" class="wx-mini-btn">Draft both captions with AI</button>
                @else
                    <p class="text-theme-xs text-gray-400">AI caption drafts sleep until the studio's model key exists — set it once on the Settings screen.</p>
                @endif

                <x-admin.field label="Caption (Urdu)" for="textUr" hint="Natural Urdu, the same message — not a word-by-word translation.">
                    <textarea id="textUr" rows="3" wire:model="textUr" class="wx-input" placeholder="ڈی ایچ اے میں دس مرلہ کا گھر، وعدے کے دن حوالے۔"></textarea>
                </x-admin.field>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-admin.field label="Link" for="linkUrl">
                        <input id="linkUrl" type="url" wire:model="linkUrl" class="wx-input" placeholder="https://woodex.com.pk/projects/">
                    </x-admin.field>
                    <x-admin.field label="First comment" for="firstComment" hint="Often where the link belongs, so the caption stays clean.">
                        <input id="firstComment" type="text" wire:model="firstComment" class="wx-input" placeholder="See the whole project: …">
                    </x-admin.field>
                </div>

                <x-admin.field label="Hashtags" for="hashtags">
                    <input id="hashtags" type="text" wire:model="hashtags" class="wx-input" placeholder="#interiordesign #lahore">
                </x-admin.field>

                <x-admin.field label="Networks" hint="At least one. Publishing itself waits for the connected accounts.">
                    <div class="flex flex-wrap gap-3">
                        @foreach (['facebook' => 'Facebook Page', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn Page'] as $network => $label)
                            <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" value="{{ $network }}" wire:model="targets" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </x-admin.field>
                @error('targets')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror

                <x-admin.field label="Photographs" hint="From the shared media library — the same photographs the website uses.">
                    <select multiple size="5" wire:model.blur="mediaIds" class="wx-input">
                        @foreach ($media as $image)
                            <option value="{{ $image->id }}">{{ $image->title ?: basename($image->path) }}</option>
                        @endforeach
                    </select>
                </x-admin.field>

                <div class="flex flex-wrap gap-3">
                    <x-admin.button type="submit" variant="secondary" icon="save">Save draft</x-admin.button>
                    <x-admin.button type="button" wire:click="submitForReview" icon="check">Submit for review</x-admin.button>
                    <x-admin.button type="button" variant="secondary" wire:click="$set('showCompose', false)">Cancel</x-admin.button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-admin.card :padding="false" title="Posts" class="xl:col-span-2">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Post</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Status</th>
                        <th class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($posts as $post)
                        <tr wire:key="post-{{ $post->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <p class="max-w-xl truncate font-medium text-gray-800 dark:text-white/90">{{ \Illuminate\Support\Str::limit($post->text, 90) }}</p>
                                <p class="mt-0.5 text-theme-xs text-gray-400">
                                    {{ collect($post->targets)->map(fn ($t) => ucfirst($t))->implode(' · ') }}
                                    · by {{ $post->author?->name ?? '—' }}
                                    @if ($post->scheduled_at) · scheduled {{ $post->scheduled_at->timezone('Asia/Karachi')->format('D j M, g:i a') }} @endif
                                    @if (count($post->media_ids) > 0) · {{ count($post->media_ids) }} photo(s) @endif
                                </p>
                                @if ($post->status === 'failed' && $post->error)
                                    <p class="mt-1 text-theme-xs text-error-600">{{ $post->error }}</p>
                                @endif
                            </td>
                            <td class="hidden px-5 py-3 md:table-cell">
                                <x-admin.badge :color="match ($post->status) {
                                    'published' => 'success',
                                    'scheduled' => 'brand',
                                    'approved' => 'success',
                                    'in_review' => 'warning',
                                    'failed' => 'danger',
                                    default => 'gray',
                                }">{{ str_replace('_', ' ', $post->status) }}</x-admin.badge>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($post->status === 'in_review')
                                        <button type="button" wire:click="approve({{ $post->id }})" class="wx-mini-btn border-brand-500 text-brand-700 dark:text-brand-300">Approve</button>
                                    @endif
                                    @if ($post->status === 'approved' || $post->status === 'scheduled')
                                        <input type="datetime-local" wire:model="scheduledAt" class="wx-input w-44 text-theme-xs" aria-label="Schedule for">
                                        <button type="button" wire:click="schedule({{ $post->id }})" class="wx-mini-btn border-brand-500 text-brand-700 dark:text-brand-300">{{ $post->status === 'scheduled' ? 'Reschedule' : 'Schedule' }}</button>
                                    @endif
                                    @if (in_array($post->status, ['in_review', 'approved', 'scheduled'], true))
                                        <button type="button" wire:click="withdraw({{ $post->id }})" class="wx-mini-btn">Back to draft</button>
                                    @endif
                                    @if (! in_array($post->status, ['published'], true))
                                        <button type="button" wire:click="remove({{ $post->id }})" wire:confirm="Delete this post?" class="text-theme-sm text-error-600 hover:underline">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-admin.empty icon="megaphone" title="Nothing composed yet" description="Write the first post — a handover, a finished room, a thought on materials." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-admin.card>

        <x-admin.card title="The calendar, shortly">
            <p class="text-theme-sm text-gray-600 dark:text-gray-300">The full month grid with drag-to-reschedule arrives with the connected accounts, when a scheduled post can really move. Until then the plan lives in the list: compose, review, approve, schedule.</p>
            <p class="mt-3 text-theme-xs text-gray-400">The scheduler runs as <code class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/10">woodex:social-publish</code> — due posts wait politely while no account is connected, and publish the moment one is.</p>
        </x-admin.card>
    </div>
</div>

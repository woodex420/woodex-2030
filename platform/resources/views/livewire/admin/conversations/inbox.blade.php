@use('App\Domain\Conversations\Enums\Channel')
@use('App\Domain\Conversations\Enums\MessageStatus')
@use('App\Domain\Conversations\Enums\MessageType')
<div class="wx-workspace" wire:poll.10s.visible="refresh">
    <x-admin.page-header title="Inbox" description="One workspace for WhatsApp and website enquiries. Keep the conversation, owner and next reply together."
                         :breadcrumbs="['Inbox' => null, 'Conversations' => null]">
        <x-slot:actions>
            @if (! $configured)
                <span class="inline-flex items-center gap-2 rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-theme-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400">
                    WhatsApp not connected
                </span>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="wx-inbox-toolbar mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex max-w-full gap-1 overflow-x-auto" role="group" aria-label="Filter conversations">
            @foreach (['all' => 'All', 'open' => 'Open', 'pending' => 'Pending', 'unread' => 'Unread', 'closed' => 'Closed'] as $key => $label)
                <button type="button" wire:click="filterStatus('{{ $key }}')" aria-pressed="{{ ($key === 'unread' ? $unreadOnly : (!$unreadOnly && $status === $key)) ? 'true' : 'false' }}" @class(['wx-underline-tab', 'is-active' => $key === 'unread' ? $unreadOnly : (!$unreadOnly && $status === $key)])>{{ $label }} @if ($key !== 'all')<span class="wx-count">{{ $counts[$key] ?? 0 }}</span>@endif</button>
            @endforeach
        </div>
        <div class="flex min-w-0 flex-wrap gap-2">
            <select wire:model.live="channel" class="wx-input w-auto" aria-label="Conversation channel"><option value="">All channels</option>@foreach ($channels as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</select>
            <select wire:model.live="assignment" class="wx-input w-auto" aria-label="Conversation assignment"><option value="">Everyone</option><option value="me">Assigned to me</option><option value="unassigned">Unassigned</option></select>
        </div>
    </div>

    @if ($pendingSelectionId !== null)
        <div class="wx-chat-pending mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 text-theme-sm" role="status">
            <p>Your current reply, internal note and attachment are kept. Discard them to switch conversations?</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="cancelSwitch" class="wx-history-button">Keep current conversation</button>
                <button type="button" wire:click="confirmSwitch" wire:loading.attr="disabled" class="wx-history-button">Discard unsent work and switch</button>
            </div>
        </div>
    @endif
    @if ($selected !== null)
        <p class="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">Filters and list pages do not close the current conversation. Unsent work stays here while this page is open.</p>
    @endif

    <div class="wx-inbox-grid">
        {{-- The list --}}
        <div @class(['wx-conversation-list overflow-hidden bg-white dark:bg-gray-900', 'hidden lg:block' => $selected !== null && ! $showList])>
            <div class="border-b border-gray-200 p-3 dark:border-gray-800">
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, number or message" class="wx-input pl-10" aria-label="Search conversations">
                </div>
            </div>

            @if ($conversations->isEmpty())
                <p class="p-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                    No conversations match these filters. New WhatsApp and website enquiries appear here.
                </p>
            @else
                <ul role="list" class="wx-conversation-scroll divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                    @foreach ($conversations as $conversation)
                        <li>
                            <button type="button" wire:click="select({{ $conversation->id }})"
                                    @class(['wx-conversation-row flex w-full gap-3 p-4 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.04]', 'is-selected bg-brand-50 dark:bg-white/[0.06]' => $selectedId === $conversation->id])>
                                <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-navy-900/10 text-theme-sm font-semibold text-navy-900 dark:bg-white/10 dark:text-white">
                                    {{ mb_strtoupper(mb_substr($conversation->contact?->name ?? $conversation->external_number ?? '?', 0, 1)) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-theme-sm font-medium text-gray-900 dark:text-white">
                                            {{ $conversation->contact?->name ?? $conversation->subject ?? $conversation->external_number ?? 'Website visitor' }}
                                        </span>
                                        <span class="shrink-0 text-theme-xs text-gray-400">
                                            {{ $conversation->last_message_at?->diffForHumans(short: true) ?? '' }}
                                        </span>
                                    </span>
                                    <span class="mt-0.5 flex items-center gap-2">
                                        @if ($conversation->last_direction === 'in')
                                            <x-icon name="arrow-left" class="size-3.5 shrink-0 text-gray-400" />
                                        @endif
                                        <span class="truncate text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $conversation->last_preview ?: 'No messages yet' }}
                                        </span>
                                        @if ($conversation->unread_count > 0)
                                            <span class="ml-auto inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-navy-900 text-theme-xs font-semibold text-white dark:bg-white dark:text-navy-900">
                                                {{ $conversation->unread_count }}
                                            </span>
                                        @endif
                                    </span>
                                    <span class="mt-1.5 flex items-center gap-2 text-[10px] text-gray-400"><span class="rounded border border-gray-200 px-1.5 dark:border-gray-700">{{ $conversation->channel->label() }}</span>{{ $conversation->assignee?->name ?? 'Unassigned' }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
            {{ $conversations->links('components.admin.pagination') }}
        </div>

        {{-- The thread --}}
        <div @class(['wx-conversation-thread min-w-0 flex-col overflow-hidden bg-white dark:bg-gray-900', 'hidden lg:flex' => $selected === null || $showList, 'flex' => $selected !== null && ! $showList])>
            @if ($selected === null)
                <div class="flex flex-1 items-center justify-center p-8 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                    Your conversations, connected. Pick a thread to read the history, add a team note or reply.
                </div>
            @else
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-4 dark:border-gray-800">
                    <button type="button" wire:click="backToList" class="wx-icon-button lg:hidden" aria-label="Back to conversations"><x-icon name="arrow-left" class="size-4" /></button>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-theme-base font-semibold text-gray-900 dark:text-white">
                            {{ $selected->contact?->name ?? $selected->subject ?? $selected->external_number ?? 'Website visitor' }}
                        </p>
                        <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $selected->contact?->phone ?? $selected->external_number }} · {{ $selected->channel->label() }}
                            @if ($selected->contact?->opted_out_at)
                                · <span class="text-error-600 dark:text-error-400">opted out {{ $selected->contact->opted_out_at->format('j M Y') }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex max-w-full shrink-0 flex-wrap items-center gap-2">
                        @if ($selected->lead && auth()->user()->can('view', $selected->lead))<a href="{{ route('admin.leads.show', $selected->lead) }}" wire:navigate class="text-theme-xs font-medium text-brand-600 dark:text-gray-300">Lead details ↗</a>@endif
                        @can('update', $selected)
                        <select wire:change="assign($event.target.value)" class="wx-input w-auto py-1.5 text-theme-xs" aria-label="Assign to">
                            <option value="0">Unassigned</option>
                            @foreach ($staff as $member)
                                <option value="{{ $member->id }}" @selected($selected->assignee_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </select>
                        @if ($selected->status === App\Domain\Conversations\Enums\ConversationStatus::Closed)
                            <x-admin.button variant="secondary" wire:click="reopen">Reopen</x-admin.button>
                        @else
                            <x-admin.button variant="secondary" wire:click="close">Close</x-admin.button>
                        @endif
                        @endcan
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-3 wx-message-canvas overflow-y-auto p-4" id="thread" x-data="{ pinned: true }" x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" x-on:scroll="pinned = $el.scrollHeight - $el.clientHeight - $el.scrollTop < 80" x-on:chat-scroll.window="if ($event.detail.reset) pinned = true; if (pinned) $nextTick(() => $el.scrollTop = $el.scrollHeight)" x-on:chat-history-page.window="pinned = false; $nextTick(() => $el.scrollTop = 0)" role="log" aria-label="Conversation messages">
                    <x-admin.conversation-history :has-earlier="$hasEarlier" :older-window="$historyBeforeId !== null" :history-limit="$historyLimit" />
                    @php($lastDay = null)
                    @foreach ($thread as $message)
                        @if ($lastDay !== $message->created_at->format('Y-m-d'))
                            <div class="wx-message-date">{{ $message->created_at->isToday() ? 'Today' : $message->created_at->format('j M Y') }}</div>
                            @php($lastDay = $message->created_at->format('Y-m-d'))
                        @endif
                        @if ($message->type === MessageType::Note)
                            <div class="mx-auto max-w-lg rounded-xl border border-dashed border-warning-300 bg-warning-50 px-3 py-2 text-theme-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
                                <span class="font-medium">Note</span> · {{ $message->author?->name }} · {{ $message->body }}
                            </div>
                        @else
                            <div @class(['flex', 'justify-end' => $message->direction === App\Domain\Conversations\Enums\MessageDirection::Out])>
                                <div @class([
                                    'wx-message-bubble max-w-[85%] rounded-2xl px-4 py-3 text-theme-sm',
                                    'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $message->direction === App\Domain\Conversations\Enums\MessageDirection::Out,
                                    'bg-white text-gray-900 shadow-theme-xs dark:bg-gray-800 dark:text-white' => $message->direction !== App\Domain\Conversations\Enums\MessageDirection::Out,
                                ])>
                                    @if ($message->media_path !== null && $message->media_disk !== null)
                                        @php($mediaUrl = route('admin.conversations.media', $message))
                                        @php($mime = (string) $message->media_mime)
                                        @if (str_starts_with($mime, 'image/'))
                                            <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="mb-1 block">
                                                <img src="{{ $mediaUrl }}" alt="{{ $message->media_name ?? 'Photograph' }}" class="max-h-56 rounded-lg" loading="lazy">
                                            </a>
                                        @elseif (str_starts_with($mime, 'video/'))
                                            <video src="{{ $mediaUrl }}" controls preload="metadata" class="mb-1 max-h-56 rounded-lg"></video>
                                        @elseif (str_starts_with($mime, 'audio/'))
                                            <audio src="{{ $mediaUrl }}" controls preload="metadata" class="mb-1"></audio>
                                        @else
                                            <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="mb-1 flex items-center gap-2 underline decoration-dotted">
                                                <x-icon name="download" class="size-4" />
                                                <span class="max-w-56 truncate">{{ $message->media_name ?? 'Document' }}</span>
                                            </a>
                                        @endif
                                    @endif
                                    @if ($message->type !== MessageType::Text && $message->type !== MessageType::Template && $message->type !== MessageType::Note)
                                        <p class="mb-1 text-theme-xs uppercase opacity-70">{{ $message->media_path === null ? $message->type->value : '' }}</p>
                                    @endif
                                    @if ($message->body !== null && $message->body !== '')
                                        <p class="break-words whitespace-pre-wrap">{{ $message->body }}</p>
                                    @endif
                                    <p class="mt-1 flex items-center gap-1.5 text-theme-xs opacity-70">
                                        {{ $message->created_at->format('H:i') }}
                                        @if ($message->direction === App\Domain\Conversations\Enums\MessageDirection::Out)
                                            · {{ $message->status->label() }}
                                            @if ($message->status === MessageStatus::Read) ✓✓ @elseif ($message->status === MessageStatus::Delivered) ✓✓ @elseif ($message->status === MessageStatus::Sent) ✓ @endif
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>

                @can('reply', $selected)
                <div class="shrink-0 border-t border-gray-100 p-4 dark:border-gray-800">
                    @if ($selected->channel === Channel::WebChat)
                        <p class="mb-2 text-theme-xs text-gray-400">Website chat · replies stay in this visitor's private thread.</p>
                    @elseif (! $selected->isInWindow())
                        <p class="mb-2 rounded-xl border border-warning-200 bg-warning-50 px-3 py-2 text-theme-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400">
                            The 24-hour window is closed. Ask the client to write, or send an approved template.
                        </p>
                    @else
                        <p class="mb-2 text-theme-xs text-gray-400">Free replies for {{ $selected->windowMinutesLeft() }} more minutes.</p>
                    @endif

                    @if ($this->cannedMatches !== [])
                        <div class="mb-2 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                            <p class="border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-theme-xs text-gray-500 dark:border-gray-800 dark:bg-white/5">Saved replies — click one to fill it in for this client:</p>
                            @foreach ($this->cannedMatches as $match)
                                <button type="button" wire:click="useCanned({{ $match['id'] }})" wire:key="canned-{{ $match['id'] }}"
                                        class="block w-full border-b border-gray-100 px-3 py-2 text-left last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $match['title'] }}
                                        @if ($match['locale'] !== 'en')<x-admin.badge color="gray">{{ strtoupper($match['locale']) }}</x-admin.badge>@endif
                                    </span>
                                    <span class="block text-theme-xs text-gray-500">{{ $match['snippet'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if ($selected->channel === Channel::Whatsapp)
                        <div class="mb-2 flex items-center gap-2">
                            <label class="cursor-pointer rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5" title="Attach a photo, PDF, voice note or clip">
                                <x-icon name="paperclip" class="size-5" />
                                <input type="file" wire:model="attachment" class="sr-only" accept="image/*,audio/*,video/*,.pdf">
                            </label>
                            @if ($attachment)
                                <div class="flex min-w-0 flex-1 items-center gap-2 rounded-lg border border-gray-200 px-2.5 py-1.5 dark:border-gray-700" wire:key="attachment-chip">
                                    @if (is_object($attachment) && str_starts_with((string) $attachment->getMimeType(), 'image/'))
                                        <img src="{{ $attachment->temporaryUrl() }}" alt="" class="size-9 shrink-0 rounded object-cover">
                                    @else
                                        <x-icon name="file" class="size-5 shrink-0 text-gray-400" />
                                    @endif
                                    <span class="min-w-0 flex-1 truncate text-theme-xs text-gray-600 dark:text-gray-300">{{ $attachment?->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeAttachment" class="rounded p-1 text-gray-400 hover:text-error-600" title="Remove">
                                        <x-icon name="x" class="size-4" />
                                    </button>
                                </div>
                            @endif
                            <div wire:loading wire:target="attachment" class="text-theme-xs text-gray-500">Uploading…</div>
                            @error('attachment') <p class="text-theme-xs text-error-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="flex items-end gap-2">
                        <textarea wire:model="draft" rows="2" placeholder="{{ $attachment ? 'Caption (optional)…' : 'Write a reply… (type / for saved replies)' }}" class="wx-input" aria-label="Reply" @disabled(! $selected->allowsFreeFormReply())></textarea>
                        <x-admin.button variant="primary" wire:click="send" wire:loading.attr="disabled" wire:target="send,attachment" icon="send" :disabled="! $selected->allowsFreeFormReply()">Send</x-admin.button>
                    </div>

                    <div class="mt-2 flex items-end gap-2">
                        <input type="text" wire:model="note" placeholder="Internal note — the client never sees it" class="wx-input text-theme-sm" aria-label="Internal note">
                        <x-admin.button variant="secondary" wire:click="addNote">Add note</x-admin.button>
                    </div>
                </div>
                @else
                    <p class="border-t border-gray-100 p-4 text-theme-xs text-gray-500 dark:border-gray-800">Read-only access · replying requires inbox permission.</p>
                @endcan
            @endif
        </div>
    </div>
</div>

<div x-data="{ drawer: $wire.entangle('drawer') }" x-on:focus.window="$wire.poll()" x-on:visibilitychange.document="if (!document.hidden) $wire.poll()" x-on:keydown.escape.window="if ($wire.drawer) $wire.closeDrawer()" @if ($canUse) wire:poll.10s.visible="poll" @endif>
    @if ($canUse)
        <div class="relative">
            <button type="button" x-ref="trigger" wire:click="openDrawer" class="relative flex size-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-100 dark:border-gray-800 dark:text-gray-400" aria-haspopup="dialog" aria-expanded="{{ $drawer ? 'true' : 'false' }}" aria-label="Website chat{{ $unread > 0 ? " ({$unread} unread)" : '' }}">
                <x-icon name="message" />
                @if ($unread > 0)<span class="absolute -top-0.5 -right-0.5 flex size-5 items-center justify-center rounded-full bg-wood-500 text-[10px] font-semibold text-white">{{ min($unread, 9) }}{{ $unread > 9 ? '+' : '' }}</span>@endif
                @if ($visitorActive)<span class="absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-white bg-success-500 dark:border-gray-900" title="A visitor is chatting right now"></span>@endif
            </button>
        </div>
        <div x-cloak x-show="$wire.drawer" x-transition.opacity class="fixed inset-0 z-[9998] bg-gray-900/40 backdrop-blur-[2px]" wire:click="closeDrawer"></div>
        <aside x-cloak x-show="$wire.drawer" x-trap.inert.noscroll="$wire.drawer" x-transition.opacity role="dialog" aria-modal="true" aria-label="Website chats" class="wx-chat-drawer fixed inset-y-0 right-0 z-[9999] flex w-full flex-col border-l border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900">
            <header class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div><h2 class="font-semibold text-navy-900 dark:text-white">Website chats</h2><p class="mt-1 text-theme-xs text-gray-500">{{ $unread }} unread conversations · {{ $activeTotal }} active</p></div>
                <div class="flex items-center gap-1">
                    @if ($canManageSound)<button type="button" wire:click="toggleSound" title="{{ $soundOn ? 'Chat sound on — click to mute' : 'Chat sound off — click to enable' }}" class="wx-icon-button" aria-label="Chat alert sound"><x-icon :name="$soundOn ? 'volume-2' : 'volume-x'" class="size-4" /></button>@endif
                    <a href="{{ route('admin.inbox', ['channel' => 'web_chat', 'status' => 'all']) }}" wire:navigate class="wx-icon-button" aria-label="Open the full inbox"><x-icon name="inbox" class="size-4" /></a>
                    <button type="button" wire:click="closeDrawer" class="wx-icon-button" aria-label="Close"><x-icon name="x" class="size-4" /></button>
                </div>
            </header>
            @if ($pendingId !== null && $pendingId !== $selectedId)
                <div class="wx-chat-pending flex shrink-0 flex-wrap items-center justify-between gap-2 border-b p-3 text-theme-xs" role="status">
                    <span>Another website thread needs attention. Your current draft is kept.</span>
                    <button type="button" wire:click="cancelSwitch" class="wx-history-button">Keep current conversation</button>
                    <button type="button" wire:click="switchPending" @if (trim($draft) !== '') wire:confirm="Switch threads and discard the current draft?" @endif class="wx-history-button">Switch to new message</button>
                </div>
            @endif
            @if ($activeTotal > $live->count())
                <div class="shrink-0 border-b border-gray-100 px-4 py-2 text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400" role="status">
                    <p>Showing the latest {{ $live->count() }} of {{ $activeTotal }} active conversations. Use the full Inbox for older conversations.</p>
                    @if ($unreadOutsideList > 0)<p class="mt-1 font-medium">{{ $unreadOutsideList }} unread {{ $unreadOutsideList === 1 ? 'conversation is' : 'conversations are' }} outside this list.</p>@endif
                </div>
            @endif
            <div class="wx-live-chat-layout flex min-h-0 flex-1">
                <nav class="wx-live-chat-list shrink-0 overflow-y-auto border-r border-gray-100 p-2 dark:border-gray-800" aria-label="Website conversations">
                    @forelse ($live as $conversation)
                        <button type="button" wire:key="lc-{{ $conversation->id }}" wire:click="openThread({{ $conversation->id }})" aria-pressed="{{ $conversation->id === $selectedId ? 'true' : 'false' }}" @class(['mb-1 block w-full rounded-xl p-3 text-left text-theme-xs', 'bg-brand-50 text-navy-900 dark:bg-white/10 dark:text-white' => $conversation->id === $selectedId, 'hover:bg-gray-50 dark:hover:bg-white/5' => $conversation->id !== $selectedId])>
                            <span class="mb-2 flex items-center gap-2"><span class="wx-avatar size-8">{{ mb_strtoupper(mb_substr($conversation->contact?->name ?? $conversation->subject ?? 'V', 0, 1)) }}</span>@if ($conversation->unread_count > 0)<span class="wx-count ml-auto">{{ $conversation->unread_count }}</span>@endif</span>
                            <span class="block truncate font-semibold">{{ $conversation->contact?->name ?? $conversation->subject ?? 'Visitor' }}</span>
                            <span class="mt-1 block truncate text-gray-500 dark:text-gray-400">{{ $conversation->last_preview ?? '—' }}</span>
                            <time class="mt-1 block text-[10px] text-gray-400">{{ $conversation->last_message_at?->diffForHumans() }}</time>
                        </button>
                    @empty
                        <p class="px-2 py-6 text-center text-theme-xs text-gray-500">No website chats yet.</p>
                    @endforelse
                </nav>
                <div class="flex min-w-0 flex-1 flex-col">
                    @if ($selectedId === null)
                        <div class="flex flex-1 items-center justify-center p-8 text-center text-theme-sm text-gray-500">Pick a conversation to read and reply.</div>
                    @else
                        <header class="shrink-0 border-b border-gray-100 p-4 dark:border-gray-800"><h3 class="truncate text-theme-sm font-semibold text-gray-900 dark:text-white">{{ $selectedConversation->contact?->name ?? $selectedConversation->subject ?? 'Website visitor' }}</h3><p class="mt-1 text-theme-xs text-gray-400">Private website thread · {{ $selectedConversation->last_message_at?->diffForHumans() ?? 'No messages yet' }}</p></header>
                        <div class="wx-message-canvas min-h-0 flex-1 space-y-3 overflow-y-auto p-4" x-data="{ pinned: true }" x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" x-on:scroll="pinned = $el.scrollHeight - $el.clientHeight - $el.scrollTop < 80" x-on:chat-scroll.window="if ($event.detail.reset) pinned = true; if (pinned) $nextTick(() => $el.scrollTop = $el.scrollHeight)" x-on:chat-history-page.window="pinned = false; $nextTick(() => $el.scrollTop = 0)" role="log" aria-label="Website chat messages">
                            <x-admin.conversation-history :has-earlier="$hasEarlier" :older-window="$historyBeforeId !== null" :history-limit="$historyLimit" />
                            @forelse ($messages as $message)
                                @if ($message->type?->value === 'note')
                                    <div wire:key="lcm-{{ $message->id }}" class="rounded-lg border border-dashed border-warning-300 bg-warning-50 p-3 text-theme-xs text-warning-700">Internal note · {{ $message->body }}</div>
                                @else
                                    <div wire:key="lcm-{{ $message->id }}" @class(['flex', 'justify-end' => $message->direction?->value === 'out'])>
                                        <div @class(['wx-message-bubble max-w-[90%] rounded-2xl px-4 py-3 text-theme-sm', 'bg-navy-900 text-white' => $message->direction?->value === 'out', 'bg-white text-gray-800 shadow-theme-xs dark:bg-gray-800 dark:text-gray-200' => $message->direction?->value !== 'out'])>
                                            <p class="break-words whitespace-pre-wrap">{{ $message->body }}</p>
                                            <time datetime="{{ $message->created_at?->toIso8601String() }}" class="mt-1 block text-right text-[10px] opacity-60">{{ $message->created_at?->format('j M · H:i') }}</time>
                                        </div>
                                    </div>
                                @endif
                            @empty
                                <p class="py-8 text-center text-theme-sm text-gray-500">No messages in this thread yet.</p>
                            @endforelse
                        </div>
                        @can('reply', $selectedConversation)
                            <form wire:submit="send" wire:key="livechat-composer-{{ $selectedId }}" class="shrink-0 border-t border-gray-100 p-3 dark:border-gray-800"><div class="flex items-end gap-2"><textarea wire:model="draft" rows="2" class="wx-input max-h-28 min-w-0 flex-1 resize-none" placeholder="Reply to the visitor…" aria-label="Reply"></textarea><x-admin.button type="button" wire:click="send" icon="send" wire:loading.attr="disabled" wire:target="send">Send</x-admin.button></div>@error('draft')<p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p>@enderror<p class="mt-2 text-[11px] text-gray-400">Replies appear on the visitor's next poll. No WhatsApp send.</p></form>
                        @else
                            <p class="border-t border-gray-100 p-4 text-theme-xs text-gray-500 dark:border-gray-800">Read-only access</p>
                        @endcan
                    @endif
                </div>
            </div>
        </aside>
    @endif
</div>

<div class="wx-workspace" wire:poll.15s.visible>
    <x-admin.page-header title="Web Chat Leads" description="Every website conversation, including unfinished enquiries. Contact details save before the service question."
        :breadcrumbs="['CRM' => null, 'Leads' => route('admin.leads.index'), 'Web Chat' => null]">
        <x-slot:actions><x-admin.button variant="secondary" :href="route('admin.inbox', ['channel' => 'web_chat'])" wire:navigate icon="inbox">Website inbox</x-admin.button></x-slot:actions>
    </x-admin.page-header>
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Website chat summary">
        @foreach (['total' => 'All conversations', 'anonymous' => 'Contact not linked', 'qualify' => 'Needs service details', 'unread' => 'Unread conversations'] as $key => $label)
            <button type="button" wire:click="$set('focus', '{{ $key === 'total' ? '' : $key }}')" class="wx-summary-card text-left"><span class="wx-eyebrow">{{ $label }}</span><strong>{{ number_format($stats[$key]) }}</strong><span class="text-theme-xs text-gray-500 dark:text-gray-400">Within your access scope</span></button>
        @endforeach
    </div>
    <div class="wx-filter-panel mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input type="search" wire:model.live.debounce.300ms="search" class="wx-input lg:col-span-2" placeholder="Name, phone, email or lead reference" aria-label="Search web chat leads">
        <select wire:model.live="focus" class="wx-input" aria-label="Web chat focus">
            <option value="">All capture stages</option><option value="anonymous">Contact not linked</option><option value="qualify">Needs service details</option><option value="unread">Unread</option><option value="unassigned">Unassigned chat</option><option value="mine">My chats</option><option value="overdue">First reply overdue</option>
        </select>
        <select wire:model.live="status" class="wx-input" aria-label="Web chat status">
            <option value="">All statuses</option><option value="open">Open</option><option value="pending">Pending</option><option value="snoozed">Snoozed</option><option value="closed">Closed</option>
        </select>
        <label class="text-theme-xs text-gray-500 dark:text-gray-400">Started from (PKT)<input type="date" wire:model.live="from" class="wx-input" aria-label="Chat started from"></label>
        <label class="text-theme-xs text-gray-500 dark:text-gray-400">Started through (PKT)<input type="date" wire:model.live="to" class="wx-input" aria-label="Chat started through"></label>
        <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 lg:col-span-6 text-theme-xs text-gray-500 dark:text-gray-400">
            <span>{{ $threads->total() }} matching conversations · {{ now()->timezone('Asia/Karachi')->format('H:i') }} PKT · refreshes while visible</span>
            <button type="button" wire:click="clearFilters" class="wx-history-button">Reset filters</button>
        </div>
        @if ($invalidDate)<p role="alert" class="text-error-600 sm:col-span-2 lg:col-span-6">Invalid date filter ignored. Choose a valid calendar date.</p>@endif
    </div>
    <div class="space-y-3" aria-label="Website enquiries">
        @forelse ($threads as $thread)
            @php
                $legacy = $thread->contact_id === null && str_starts_with((string) $thread->widget_state, 'asked_service|');
                $provided = $thread->contact?->phone ?? $thread->contact?->email;
                if ($legacy) {
                    $raw = substr($thread->widget_state, strlen('asked_service|'));
                    $provided = str_starts_with($raw, 'email:') ? (filter_var(substr($raw, 6), FILTER_VALIDATE_EMAIL) ?: null) : \App\Support\Phone::normalize($raw);
                }
            @endphp
            <article wire:key="web-lead-{{ $thread->id }}" class="wx-web-lead rounded-2xl border p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="wx-avatar">{{ mb_strtoupper(mb_substr($thread->contact?->name ?? $thread->subject ?? 'V', 0, 1)) }}</span>
                        <div class="min-w-0"><h2 class="break-words font-semibold">{{ $thread->contact?->name ?? $thread->subject ?? 'Anonymous website visitor' }}</h2><p class="mt-1 break-all text-theme-xs text-gray-500 dark:text-gray-400">{{ $provided ?? 'No linked contact details yet' }} @if ($provided) · provided, ownership unverified @endif</p></div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-theme-xs"><span class="wx-count">{{ $thread->status->label() }}</span>@if ($thread->unread_count > 0)<span class="wx-count">{{ $thread->unread_count }} unread</span>@endif</div>
                </div>
                <div class="mt-4 grid gap-3 text-theme-xs sm:grid-cols-2 lg:grid-cols-4">
                    <div><span class="wx-eyebrow">Capture</span><p class="mt-1">{{ $legacy ? 'Legacy intake · CRM capture pending' : ($thread->contact_id === null ? 'Contact not provided' : (str_starts_with((string) $thread->widget_state, 'asked_service') ? 'Saved · needs service details' : 'Contact captured · team follow-up')) }}</p></div>
                    <div><span class="wx-eyebrow">Chat owner</span><p class="mt-1">{{ $thread->assignee?->name ?? 'Unassigned' }}</p></div>
                    <div><span class="wx-eyebrow">Activity</span><p class="mt-1">{{ $thread->messages_count }} messages · {{ $thread->last_message_at?->diffForHumans() ?? 'No activity' }}</p></div>
                    <div><span class="wx-eyebrow">First reply target</span><p class="mt-1">{{ $thread->lead?->first_response_at ? 'Staff replied' : ($thread->lead?->sla_due_at ? $thread->lead->sla_due_at->timezone('Asia/Karachi')->format('d M, H:i').' PKT' : 'Not scheduled') }}</p></div>
                </div>
                <p class="mt-3 line-clamp-2 break-words text-theme-sm text-gray-500 dark:text-gray-400">{{ $thread->messages->first()?->body ?? 'Waiting for a visitor message' }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-admin.button size="sm" wire:click="$dispatch('open-website-thread', { id: {{ $thread->id }} })" icon="message">Open conversation</x-admin.button>
                    @if ($thread->lead && auth()->user()->can('view', $thread->lead))<x-admin.button size="sm" variant="secondary" :href="route('admin.leads.show', $thread->lead)" wire:navigate>{{ $thread->lead->ref }}</x-admin.button>@endif
                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">Website chat · started {{ $thread->created_at->timezone('Asia/Karachi')->format('d M Y, H:i') }} PKT</span>
                </div>
            </article>
        @empty
            <div class="wx-web-lead rounded-2xl border p-10 text-center"><h2 class="font-semibold">No website conversations match</h2><p class="mt-2 text-gray-500 dark:text-gray-400">Try resetting the filters. Anonymous and unfinished chats appear here as visitors start them.</p></div>
        @endforelse
    </div>
    <div class="mt-4">{{ $threads->links() }}</div>
    <p class="mt-4 text-theme-xs text-gray-500 dark:text-gray-400">Native SQL records, not browser-only history. Service details are optional qualification; a saved enquiry is not marketing consent. New page-view/UTM tracking is a later approved storage phase.</p>
</div>

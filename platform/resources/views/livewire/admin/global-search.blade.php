@use('App\Support\Phone')
<div x-data="{ open: false }"
     x-on:keydown.window.ctrl.k.prevent="open = true; $nextTick(() => $refs.term.focus())"
     x-on:keydown.window.meta.k.prevent="open = true; $nextTick(() => $refs.term.focus())"
     x-on:focus-global-search.window="open = true; $nextTick(() => $refs.term.focus())"
     x-on:open-search.window="open = true; $nextTick(() => $refs.term.focus())"
     class="max-w-md flex-1">
    <button type="button" x-ref="trigger" x-on:click="open = true; $nextTick(() => $refs.term.focus())"
            class="flex h-10 w-full items-center gap-2.5 rounded-lg border border-gray-200 bg-gray-50 px-3.5 text-left text-theme-sm text-gray-400 transition hover:border-gray-300 hover:bg-white dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700"
            aria-label="Search (Ctrl K)">
        <x-icon name="search" class="size-4" />
        <span class="hidden flex-1 truncate md:block">Search screens, names or references…</span>
        <kbd class="ml-auto hidden rounded border border-gray-200 bg-white px-1.5 py-0.5 font-sans text-[11px] text-gray-500 md:inline dark:border-gray-700 dark:bg-gray-800">Ctrl K</kbd>
    </button>

    @teleport('body')
        <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-[10000] flex items-start justify-center bg-gray-900/50 p-4 pt-[10vh] backdrop-blur-[2px]"
             x-on:keydown.escape.window="open = false; $nextTick(() => $refs.trigger.focus())" x-on:click.self="open = false" role="dialog" aria-modal="true" aria-label="Search">
            <div class="flex max-h-[80dvh] w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-white shadow-theme-xl dark:bg-gray-900" x-trap.noscroll="open">
                <div class="flex items-center gap-3 border-b border-gray-100 px-4 dark:border-gray-800">
                    <x-icon name="search" class="size-5 text-gray-400" />
                    <input x-ref="term" type="search" wire:model.live.debounce.250ms="term" placeholder="Search screens, people, projects and document references…"
                           maxlength="160" class="h-14 min-w-0 flex-1 border-0 bg-transparent text-theme-sm text-gray-800 placeholder:text-gray-400 focus:ring-0 focus:outline-none dark:text-white"
                           x-on:keydown.down.prevent="$focus.within($refs.results).first()" autocomplete="off" aria-label="Search">
                    <span wire:loading wire:target="term" class="size-4 animate-spin rounded-full border-2 border-gray-300 border-t-navy-700"></span>
                    <button type="button" aria-label="Close search" x-on:click="open = false; $nextTick(() => $refs.trigger.focus())" class="shrink-0 rounded border border-gray-200 px-1.5 py-0.5 text-[11px] text-gray-400 dark:border-gray-700">Esc</button>
                </div>

                <div x-ref="results" class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2"
                     x-on:keydown.down.prevent="$focus.wrap().next()" x-on:keydown.up.prevent="$focus.wrap().previous()">
                    @if ($pages->isNotEmpty())
                        <p class="px-3 pt-2 pb-1 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Go to · {{ $pages->count() }} screens</p>
                        @foreach ($pages as $page)
                            <a href="{{ $page['url'] }}" @unless ($page['fullDocument']) wire:navigate @endunless x-on:click="open = false" class="flex items-center gap-3 rounded-lg px-3 py-2 text-theme-sm text-gray-700 hover:bg-gray-50 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-white/5 dark:focus:bg-white/10">
                                <x-icon :name="$page['icon']" class="size-4 text-gray-400" /> {{ $page['label'] }}
                            </a>
                        @endforeach
                    @endif
                    @if ($searched)
                        @if ($leads->isNotEmpty())
                            <p class="px-3 pt-2 pb-1 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Leads</p>
                            @foreach ($leads as $lead)
                                <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate x-on:click="open = false" wire:key="sl-{{ $lead->id }}"
                                   class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-gray-50 focus:bg-gray-100 focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/10">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-navy-50 text-navy-700 dark:bg-white/10 dark:text-white"><x-icon name="users" class="size-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $lead->contact->name }}</span>
                                        <span class="block truncate text-theme-xs text-gray-500">{{ $lead->ref }} · {{ $lead->business_line->label() }}</span>
                                    </span>
                                    <x-admin.badge :color="$lead->stage->badgeColor()">{{ $lead->stage->name }}</x-admin.badge>
                                </a>
                            @endforeach
                        @endif
                        @if ($contacts->isNotEmpty())
                            <p class="px-3 pt-3 pb-1 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Contacts</p>
                            @foreach ($contacts as $contact)
                                <a href="{{ route('admin.contacts.show', $contact) }}" wire:navigate x-on:click="open = false" wire:key="sc-{{ $contact->id }}"
                                   class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-gray-50 focus:bg-gray-100 focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/10">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-sand-100 text-wood-700 dark:bg-wood-500/10 dark:text-wood-300"><x-icon name="contact" class="size-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $contact->name }}</span>
                                        <span class="block truncate text-theme-xs text-gray-500">{{ Phone::display($contact->phone) ?: $contact->email }} · {{ $contact->leads_count }} {{ \Illuminate\Support\Str::plural('enquiry', $contact->leads_count) }}</span>
                                    </span>
                                    @if ($contact->is_client)<x-admin.badge color="success" :dot="false">Client</x-admin.badge>@endif
                                </a>
                            @endforeach
                        @endif
                        @if ($companies->isNotEmpty())
                            <p class="px-3 pt-3 pb-1 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Companies</p>
                            @foreach ($companies as $company)
                                <a href="{{ route('admin.companies.show', $company) }}" wire:navigate x-on:click="open = false" wire:key="sco-{{ $company->id }}"
                                   class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-gray-50 focus:bg-gray-100 focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/10">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-navy-50 text-navy-700 dark:bg-white/10 dark:text-white"><x-icon name="building" class="size-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $company->name }}</span>
                                        <span class="block truncate text-theme-xs text-gray-500">{{ $company->typeLabel() }} · {{ $company->contacts_count }} {{ \Illuminate\Support\Str::plural('person', $company->contacts_count) }}</span>
                                    </span>
                                </a>
                            @endforeach
                        @endif
                        @if ($quotes->isNotEmpty())
                            <p class="px-3 pt-3 pb-1 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Quotations</p>
                            @foreach ($quotes as $quote)
                                <a href="{{ route('admin.quotes.show', $quote) }}" wire:navigate x-on:click="open = false" wire:key="sq-{{ $quote->id }}"
                                   class="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-gray-50 focus:bg-gray-100 focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/10">
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-navy-50 text-navy-700 dark:bg-white/10 dark:text-white"><x-icon name="quote" class="size-4" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $quote->label() }} · {{ $quote->contact?->name }}</span>
                                        <span class="block truncate text-theme-xs text-gray-500">{{ $quote->title }}</span>
                                    </span>
                                    <x-admin.badge :color="$quote->status->color()">{{ $quote->status->label() }}</x-admin.badge>
                                </a>
                            @endforeach
                        @endif
                        @foreach ($records->groupBy('group') as $group => $matches)
                            <p class="px-3 pt-3 pb-1 text-theme-xs font-medium tracking-wide text-gray-400 uppercase">{{ $group }}</p>
                            @foreach ($matches as $match)
                                <a href="{{ $match['url'] }}" wire:navigate x-on:click="open = false" class="block rounded-lg px-3 py-2 hover:bg-gray-50 focus:bg-gray-100 focus:outline-none dark:hover:bg-white/5 dark:focus:bg-white/10">
                                    <span class="block truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $match['label'] }}</span>
                                    <span class="block truncate text-theme-xs text-gray-500">{{ $match['detail'] }}</span>
                                </a>
                            @endforeach
                        @endforeach
                        @if ($pages->isEmpty() && $leads->isEmpty() && $contacts->isEmpty() && $companies->isEmpty() && $quotes->isEmpty() && $records->isEmpty())
                            <div class="px-3 py-10 text-center">
                                <p class="text-theme-sm text-gray-600 dark:text-gray-400">Nothing found for “{{ $term }}”.</p>
                                <p class="mt-1 text-theme-xs text-gray-400">Try a screen name, phone number, project code, lead reference, invoice, receipt or quotation number.</p>
                            </div>
                        @endif
                    @endif
                </div>
                <div class="flex items-center gap-4 border-t border-gray-100 px-4 py-2.5 text-[11px] text-gray-400 dark:border-gray-800">
                    <span><kbd class="font-sans">↑</kbd> <kbd class="font-sans">↓</kbd> to move</span>
                    <span><kbd class="font-sans">Enter</kbd> to open</span>
                </div>
            </div>
        </div>
    @endteleport
</div>

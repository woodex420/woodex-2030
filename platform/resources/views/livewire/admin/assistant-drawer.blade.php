<div x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:open-assistant.window="open = true; $nextTick(() => $refs.question?.focus())">
    <button type="button" @click="open = true; $nextTick(() => $refs.question?.focus())"
            class="flex size-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-100 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5"
            aria-label="Open the assistant" title="Assistant — records and safe diagnostics">
        <x-icon name="sparkles" class="size-5" />
    </button>

    @teleport('body')
        <div x-show="open" x-cloak class="fixed inset-0 z-[10000]" role="dialog" aria-modal="true" aria-label="Assistant">
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-gray-900/50 backdrop-blur-[2px]" @click="open = false"></div>

            <aside x-show="open"
                   x-transition:enter="transition duration-300 ease-out"
                   x-transition:enter-start="translate-x-full"
                   x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition duration-200 ease-in"
                   x-transition:leave-start="translate-x-0"
                   x-transition:leave-end="translate-x-full"
                   x-trap.noscroll="open"
                   class="absolute top-0 right-0 flex h-full w-full max-w-md flex-col bg-white shadow-theme-xl dark:bg-gray-900">
                <div class="flex items-start gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <x-icon name="sparkles" class="mt-0.5 size-5 shrink-0 text-gray-400" />
                    <div class="min-w-0 flex-1">
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Assistant</p>
                        <p class="text-theme-xs text-gray-500">Cited records and permission-safe diagnostics. Read-only recommendations never change settings or send messages. External AI is not connected.</p>
                    </div>
                    <button type="button" @click="open = false" class="flex size-8 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Close the assistant">
                        <x-icon name="x" class="size-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    @if ($turns === [])
                        <p class="text-theme-xs font-medium tracking-wide text-gray-400 uppercase">Ask about</p>
                    @endif
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($questions as $question)
                            <button type="button" wire:click="askChip('{{ $question['id'] }}')" wire:key="chip-{{ $question['id'] }}"
                                    class="rounded-full border border-gray-300 px-3 py-1.5 text-theme-xs font-medium text-gray-600 hover:border-brand-300 hover:text-brand-700 dark:border-gray-700 dark:text-gray-300 dark:hover:border-brand-500">
                                {{ $question['label'] }}
                            </button>
                        @endforeach
                    </div>
                    @if ($questions === [])
                        <p class="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">None of the assistant's questions match your permissions yet.</p>
                    @endif

                    <span wire:loading wire:target="askChip,askDraft" class="mt-4 inline-block size-4 animate-spin rounded-full border-2 border-gray-300 border-t-navy-700"></span>

                    @foreach ($turns as $index => $turn)
                        <div class="mt-5" wire:key="turn-{{ $index }}">
                            <p class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md wx-primary-bubble px-3 py-2 text-theme-sm">{{ $turn['question'] }}</p>
                            <div class="mt-2 max-w-[92%] rounded-2xl rounded-tl-md bg-gray-50 px-3 py-2 dark:bg-white/5">
                                <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ $turn['answer']['headline'] }}</p>
                                @foreach ($turn['answer']['rows'] as $row)
                                    <a href="{{ $row['url'] }}" wire:navigate @click="open = false"
                                       class="mt-1.5 flex min-w-0 flex-col gap-1 rounded-lg px-2 py-1 hover:bg-white dark:hover:bg-white/5">
                                        <span class="min-w-0 break-words text-theme-sm font-medium text-brand-700 dark:text-brand-300">{{ $row['label'] }}</span>
                                        <span class="max-w-full break-words text-theme-xs leading-relaxed text-gray-600 dark:text-gray-400">{{ $row['detail'] }}</span>
                                    </a>
                                @endforeach
                                @if ($turn['answer']['source'] !== '')
                                    <p class="mt-1.5 px-2 text-theme-xs text-gray-400">Read from: {{ $turn['answer']['source'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <form wire:submit="askDraft" class="flex items-center gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                    <input x-ref="question" type="text" wire:model="draft" placeholder="Ask, e.g. what is overdue?"
                           class="h-11 flex-1 rounded-lg border border-gray-200 bg-transparent px-3 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-400 focus:ring-0 focus:outline-none dark:border-gray-700 dark:text-white"
                           autocomplete="off" aria-label="Ask the assistant">
                    <x-admin.button type="submit" icon="send">Ask</x-admin.button>
                </form>
                <div class="flex items-center justify-between px-5 pb-3">
                    <p class="text-theme-xs text-gray-400">This thread isn't saved — closing the page clears it.</p>
                    @if ($turns !== [])
                        <button type="button" wire:click="clearThread" class="text-theme-xs font-medium text-gray-500 hover:underline">Clear</button>
                    @endif
                </div>
            </aside>
        </div>
    @endteleport
</div>

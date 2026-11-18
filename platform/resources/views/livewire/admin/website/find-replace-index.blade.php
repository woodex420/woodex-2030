<div>
    <x-admin.page-header title="Find & replace"
                         description="Change one piece of text on every page at once — the phone number, the email, the address. Preview lists every page it matches and how many times, before anything is written. Each page is kept as a version, so the whole run can be undone."
                         :breadcrumbs="['Website' => null, 'Find & replace' => null]" />

    @if ($result !== '')
        <div class="mb-4 rounded-2xl border border-gray-200 bg-gray-50 p-3 text-theme-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
            {{ $result }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-admin.card title="The change" description="Plain text, not a pattern — type it exactly as the pages hold it." class="xl:col-span-1">
            <form wire:submit="runPreview" class="space-y-3">
                <x-admin.field label="Find" for="find" hint="At least 3 characters. It changes every occurrence, including tel: and mailto: links and the structured data.">
                    <input id="find" type="text" wire:model="find" class="wx-input" placeholder="+92 322 4000768">
                </x-admin.field>

                <x-admin.field label="Replace with" for="replace" hint="Leave empty to remove the text.">
                    <input id="replace" type="text" wire:model="replace" class="wx-input" placeholder="+92 300 1234567">
                </x-admin.field>

                <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="matchCase" class="rounded border-gray-300">
                    Match case
                </label>

                <div>
                    <p class="mb-1 text-theme-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Areas</p>
                    <p class="mb-2 text-theme-xs text-gray-500 dark:text-gray-400">
                        Tick an area to limit the change; leave all unticked to search the whole site
                        ({{ number_format($pageCount) }} pages).
                    </p>
                    @foreach ($areaOptions as $key => $label)
                        <label class="flex items-center gap-2 py-0.5 text-theme-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="sections" value="{{ $key }}" class="rounded border-gray-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                <x-admin.button type="submit" icon="search">Preview matches</x-admin.button>
            </form>
        </x-admin.card>

        <x-admin.card title="What will change" class="xl:col-span-2">
            @if ($preview === null)
                <x-admin.empty icon="search" title="Nothing previewed yet"
                               description="Type the text to find and press Preview matches. Nothing is written until you press Replace everywhere." />
            @elseif ($preview['total'] === 0)
                <x-admin.empty icon="search" title="No page contains that text"
                               description="Nothing would change. Check the spelling, the spaces and Match case, or try a shorter piece of the text." />
            @else
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <x-admin.badge color="wood" :dot="false">{{ number_format($preview['total']) }} match(es)</x-admin.badge>
                    <x-admin.badge color="gray" :dot="false">{{ count($preview['pages']) }} of {{ number_format($preview['scanned']) }} page(s)</x-admin.badge>
                    <x-admin.badge color="success" :dot="false">Nothing written yet</x-admin.badge>
                </div>

                <div class="max-h-80 overflow-auto rounded-xl border border-gray-100 dark:border-gray-800">
                    <table class="min-w-full text-left text-theme-sm">
                        <thead class="sticky top-0 border-b border-gray-100 bg-white text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800 dark:bg-gray-900">
                            <tr>
                                <th class="px-4 py-2 font-medium">Page</th>
                                <th class="px-4 py-2 text-right font-medium">Matches</th>
                                <th class="hidden px-4 py-2 font-medium lg:table-cell">First match</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($preview['pages'] as $page)
                                <tr wire:key="fr-{{ $page['path'] }}">
                                    <td class="px-4 py-2 font-mono text-theme-xs text-gray-700 dark:text-gray-300">{{ $page['url'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $page['count'] }}</td>
                                    <td class="hidden max-w-md truncate px-4 py-2 text-theme-xs text-gray-500 lg:table-cell" title="{{ $page['sample'] }}">
                                        {{ $page['sample'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <x-admin.button wire:click="apply" icon="refresh" :disabled="$signature === ''">
                        Replace everywhere
                    </x-admin.button>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Every page is snapshotted first. If the site changed since this preview, nothing is written and you are asked to preview again.
                    </p>
                </div>
            @endif
        </x-admin.card>
    </div>

    <x-admin.card :padding="false" title="Recent runs" description="The before-image of every page each run touched. Undo restores all of them at once." class="mt-6">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">When</th>
                        <th class="px-5 py-3 font-medium">Changed</th>
                        <th class="px-5 py-3 text-right font-medium">Pages</th>
                        <th class="px-5 py-3 text-right font-medium">Matches</th>
                        <th class="px-5 py-3"><span class="sr-only">Undo</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run['id'] }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3 whitespace-nowrap text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::parse($run['at'])->format('j M Y, H:i') }}
                            </td>
                            <td class="px-5 py-3 font-mono text-theme-xs text-gray-700 dark:text-gray-300">
                                {{ $run['find'] }} → {{ $run['replace'] === '' ? '(removed)' : $run['replace'] }}
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ $run['files'] }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ number_format($run['total']) }}</td>
                            <td class="px-5 py-3 text-right">
                                @if ($run['undone'])
                                    <span class="text-theme-xs text-gray-400">Undone</span>
                                @else
                                    <button type="button" wire:click="undo('{{ $run['id'] }}')" wire:confirm="Put every page of this run back as it was?"
                                            class="inline-flex items-center gap-1 text-theme-sm text-error-600 hover:underline">
                                        Undo
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-admin.empty icon="undo" title="No runs yet"
                                               description="A run appears here after Replace everywhere, with the before-image of every page it touched." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>

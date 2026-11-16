<div>
    <x-admin.page-header title="Redirects" description="Every URL of the previous website that no longer exists sends its visitors to the closest new page with one 301. A URL that answers 404 loses the ranking it earned over years."
                         :breadcrumbs="['Website' => null, 'Redirects' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="download" wire:click="exportCsv">Export CSV</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
        <x-admin.stat label="Redirects" :value="$total" icon="globe" />
        <x-admin.stat label="Visitors redirected" :value="$visits" icon="users" tone="wood" />
        <x-admin.stat label="Not firing" :value="$notFiring" icon="alert" :tone="$notFiring > 0 ? 'warning' : 'success'" />
    </div>

    @if ($notFiring > 0)
        <div class="mb-4 rounded-2xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300">
            <b>{{ $notFiring }} redirect(s) cannot fire.</b> The website is its page files, and the web server answers a
            file before the app runs — so an old URL that is still a page shows the page, not the redirect. Delete the
            page in <b>All pages</b> and the redirect starts working; the rows stay listed and marked below.
        </div>
    @endif

    @if ($result !== '')
        <div class="mb-4 rounded-2xl border border-gray-200 bg-gray-50 p-3 text-theme-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
            {{ $result }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-admin.card title="Add a redirect" class="xl:col-span-1">
            <form wire:submit="add" class="space-y-3">
                <x-admin.field label="Old URL" for="fromPath" hint="The address from the previous website, for example /apartment-interior-design/. An address that is still a page cannot be redirected — delete the page first.">
                    <input id="fromPath" type="text" wire:model="fromPath" class="wx-input" placeholder="/apartment-interior-design/">
                </x-admin.field>
                <x-admin.field label="New URL or page" for="toPath">
                    <input id="toPath" type="text" wire:model="toPath" class="wx-input" placeholder="/interior-design/">
                    <select class="wx-input mt-2" onchange="this.previousElementSibling.value = this.value">
                        <option value="">…or pick a published page</option>
                        @foreach ($pages as $page)
                            <option value="{{ $page->slug }}">{{ $page->slug }} · {{ $page->title }}</option>
                        @endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Type" for="code">
                    <select id="code" wire:model="code" class="wx-input">
                        <option value="301">301 — permanent (use this)</option>
                        <option value="302">302 — temporary</option>
                    </select>
                </x-admin.field>
                <x-admin.field label="Note" for="note">
                    <input id="note" type="text" wire:model="note" class="wx-input" placeholder="Was a service page on the old site">
                </x-admin.field>
                <x-admin.button type="submit" icon="plus">Add redirect</x-admin.button>
            </form>
        </x-admin.card>

        <x-admin.card title="Import a CSV" description="One redirect per line: old URL, new URL, optional 301/302, optional note. A spreadsheet copy (tab-separated) works too. Re-running an import updates the rows it already knows." class="xl:col-span-2">
            <div class="space-y-3">
                <textarea rows="6" wire:model="csv" class="wx-input font-mono text-theme-xs" placeholder="/apartment-interior-design/,/interior-design/,301,was a separate service page"></textarea>
                <div class="flex items-center gap-3">
                    <x-admin.button variant="secondary" icon="upload" wire:click="importCsv" :disabled="trim($csv) === ''">Import</x-admin.button>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Old URLs that are still pages are skipped and listed — a file always answers before a redirect does.</p>
                </div>
            </div>
        </x-admin.card>
    </div>

    <x-admin.card :padding="false" title="All redirects" class="mt-6">
        <div class="mb-4 px-5 pt-5">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search an old or new URL" class="wx-input" aria-label="Search redirects">
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Old URL</th>
                        <th class="px-5 py-3 font-medium">New URL</th>
                        <th class="px-5 py-3 font-medium">Type</th>
                        <th class="px-5 py-3 text-right font-medium">Visitors</th>
                        <th class="hidden px-5 py-3 font-medium lg:table-cell">Note</th>
                        <th class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($redirects as $redirect)
                        <tr wire:key="redirect-{{ $redirect->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3 font-mono text-theme-xs text-gray-700 dark:text-gray-300">
                                {{ $redirect->from_path }}
                                @if (in_array($redirect->from_path, $inert, true))
                                    <x-admin.badge color="warning" :dot="false" class="ml-1">not firing</x-admin.badge>
                                @endif
                            </td>
                            <td class="px-5 py-3 font-mono text-theme-xs text-gray-700 dark:text-gray-300">{{ $redirect->to_path }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $redirect->code }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ $redirect->hits }}</td>
                            <td class="hidden px-5 py-3 text-theme-xs text-gray-500 lg:table-cell">{{ $redirect->note }}</td>
                            <td class="px-5 py-3 text-right">
                                <button type="button" wire:click="remove({{ $redirect->id }})" class="text-theme-sm text-error-600 hover:underline">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-admin.empty icon="globe" title="No redirects yet" description="Paste the CSV of the old website's URLs above — one old URL and its new address per line." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>

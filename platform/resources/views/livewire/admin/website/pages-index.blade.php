<div>
    <x-admin.page-header title="Pages" description="Every page of the public website is a file under public/. The builder edits that file, and the file is what a visitor gets."
                         :breadcrumbs="['Website' => null, 'Pages' => null]">
        <x-slot:actions>
            {{-- The builder is a full document of its own: a normal link, not wire:navigate. New pages are made there. --}}
            <x-admin.button icon="panel" :href="route('admin.website.builder')">Page builder</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-admin.stat label="Pages" :value="$counts['pages']" />
        <x-admin.stat label="Sections" :value="$counts['sections']" />
        <x-admin.stat label="Changed this week" :value="$counts['recent']" />
        <x-admin.stat label="Images" :value="$counts['media']" />
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <div class="relative md:col-span-3">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search a title, an address or a file" class="wx-input pl-10" aria-label="Search pages">
        </div>
        <select wire:model.live="section" class="wx-input" aria-label="Section">
            <option value="">Every section ({{ count($sections) }})</option>
            @foreach ($sections as $value)
                <option value="{{ $value }}">{{ match ($value) { 'home' => 'Home page', 'top-level' => 'Top-level pages', default => ucfirst(str_replace('-', ' ', $value)) } }}</option>
            @endforeach
        </select>
    </div>

    <x-admin.card :padding="false">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr>
                        <th class="px-5 py-3 font-medium">Page</th>
                        <th class="hidden px-5 py-3 font-medium md:table-cell">Section</th>
                        <th class="hidden px-5 py-3 font-medium lg:table-cell">Size</th>
                        <th class="hidden px-5 py-3 font-medium lg:table-cell">Changed</th>
                        <th class="px-5 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($pages as $page)
                        <tr wire:key="page-{{ $page['path'] }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.website.builder', ['path' => $page['path']]) }}" class="block font-medium text-gray-800 hover:underline dark:text-white/90">{{ $page['title'] }}</a>
                                <span class="block text-theme-xs text-gray-500">{{ $page['url'] }} · {{ $page['path'] }}</span>
                            </td>
                            <td class="hidden px-5 py-3 text-gray-600 md:table-cell dark:text-gray-400">
                                {{ match ($page['section']) { 'home' => 'Home page', 'top-level' => 'Top-level', default => ucfirst(str_replace('-', ' ', $page['section'])) } }}
                            </td>
                            <td class="hidden px-5 py-3 text-gray-500 lg:table-cell">{{ number_format($page['size'] / 1024, 1) }} KB</td>
                            <td class="hidden px-5 py-3 text-gray-500 lg:table-cell">{{ \Illuminate\Support\Carbon::createFromTimestamp($page['mtime'])->diffForHumans() }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.website.builder', ['path' => $page['path']]) }}" class="font-medium text-theme-sm text-brand-600 hover:underline">Edit in builder</a>
                                    <a href="{{ $page['url'] }}" target="_blank" rel="noopener" class="text-theme-sm text-brand-600 hover:underline">View</a>
                                    @can('website.publish')
                                        <button type="button" wire:click="retire('{{ $page['path'] }}')"
                                                wire:confirm="Take “{{ $page['title'] }}” off the site? The file is kept in storage, so it can be put back."
                                                class="text-theme-sm text-error-600 hover:underline">Take offline</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-admin.empty title="No page matches" description="Clear the search or pick another section." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <p class="mt-3 text-theme-xs text-gray-500">
        A new page is created in the builder, which writes its file. Nothing on this screen is stored in the
        database: what you see is what the web server serves.
    </p>
</div>

<div>
    <x-admin.page-header title="Accounting periods" description="Posting needs an open period covering the business date. Closing a period blocks backdated entries until it is deliberately reopened — both acts are recorded with who and when."
                         :breadcrumbs="['Accounts' => null, 'Periods' => null]">
        <x-slot:actions>
            @if ($canManage)
                <x-admin.button icon="plus" wire:click="create">New period</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($showForm)
        <x-admin.card class="mb-6 border-brand-200 dark:border-brand-800" title="New period">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-4">
                <div>
                    <label class="wx-label">Name</label>
                    <input wire:model="name" class="wx-input" placeholder="e.g. July 2027" maxlength="60" />
                    @error('name')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="wx-label">Starts on</label>
                    <input type="date" wire:model="startsOn" class="wx-input" />
                    @error('startsOn')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="wx-label">Ends on</label>
                    <input type="date" wire:model="endsOn" class="wx-input" />
                    @error('endsOn')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-end gap-2">
                    <x-admin.button variant="secondary" type="button" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                    <x-admin.button type="submit">Save</x-admin.button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <x-admin.card title="Periods">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Period</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Covers</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Status</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Closed</th>
                        @if ($canManage)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($periods as $period)
                        <tr>
                            <td class="font-medium">{{ $period->name }}</td>
                            <td class="text-theme-sm">{{ $period->starts_on->format('d M Y') }} → {{ $period->ends_on->format('d M Y') }}</td>
                            <td class="text-theme-sm">
                                @if ($period->isOpen())
                                    <x-admin.badge color="success">Open</x-admin.badge>
                                @else
                                    <x-admin.badge color="error">Closed</x-admin.badge>
                                @endif
                            </td>
                            <td class="text-theme-sm">
                                @if ($period->closed_at)
                                    {{ $period->closed_at->format('d M Y H:i') }} by {{ $period->closer?->name ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="text-right">
                                    @if ($period->isOpen())
                                        <x-admin.button size="sm" variant="ghost" wire:click="close({{ $period->id }})" wire:confirm="Close {{ $period->name }}? Backdated entries into it will be blocked.">Close</x-admin.button>
                                    @else
                                        <x-admin.button size="sm" variant="ghost" wire:click="reopen({{ $period->id }})" wire:confirm="Reopen {{ $period->name }}? Closing history stays in the audit log.">Reopen</x-admin.button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-theme-sm text-gray-500">No periods yet. The chart seeder creates six monthly periods around today.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>
</div>

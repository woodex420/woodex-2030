<div>
    <x-admin.page-header title="Tasks" :description="$counts['open'].' open'.($counts['overdue'] ? ' · '.$counts['overdue'].' overdue' : '')"
                         :breadcrumbs="['CRM' => null, 'Tasks' => null]">
        <x-slot:actions>
            <x-admin.button icon="plus" wire:click="openCreate">New task</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03]" role="tablist">
            @foreach (['open' => 'Open', 'done' => 'Completed'] as $value => $label)
                <button type="button" wire:click="$set('show', '{{ $value }}')" role="tab" aria-selected="{{ $show === $value ? 'true' : 'false' }}"
                        @class(['rounded-md px-3 py-1.5 text-theme-sm font-medium transition', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $show === $value, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $show !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        @if ($canSeeTeam)
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-white/[0.03]" role="tablist">
                @foreach (['mine' => 'Mine', 'team' => 'Whole team'] as $value => $label)
                    <button type="button" wire:click="$set('scope', '{{ $value }}')" role="tab" aria-selected="{{ $scope === $value ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 text-theme-sm font-medium transition', 'bg-navy-900 text-white dark:bg-white dark:text-navy-900' => $scope === $value, 'text-gray-600 hover:text-gray-900 dark:text-gray-400' => $scope !== $value])>{{ $label }}</button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-6" wire:loading.class="opacity-60">
        @forelse ($groups as $label => $tasks)
            <x-admin.card :padding="false">
                <header class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h2 @class(['text-theme-sm font-semibold', 'text-error-600' => $label === 'Overdue', 'text-gray-800 dark:text-white/90' => $label !== 'Overdue'])>{{ $label }}</h2>
                    <span class="text-theme-xs text-gray-400">{{ $tasks->count() }}</span>
                </header>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($tasks as $task)
                        @php $done = $task->status === \App\Domain\Crm\Enums\TaskStatus::Done; @endphp
                        <li class="group flex items-start gap-3 px-5 py-3" wire:key="task-{{ $task->id }}">
                            <button type="button" wire:click="toggle({{ $task->id }})" aria-label="{{ $done ? 'Reopen' : 'Complete' }} task"
                                    @class(['mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border transition', 'border-success-500 bg-success-500 text-white' => $done, 'border-gray-300 text-transparent hover:border-success-500 hover:text-success-500 dark:border-gray-600' => ! $done])>
                                <x-icon name="check" class="size-3.5" />
                            </button>
                            <div class="min-w-0 flex-1">
                                <p @class(['text-theme-sm', 'text-gray-400 line-through' => $done, 'text-gray-800 dark:text-white/90' => ! $done])>
                                    {{ $task->title }}
                                    @if ($task->priority === \App\Domain\Crm\Enums\LeadPriority::High && ! $done)
                                        <x-admin.badge color="error" :dot="false" class="ml-1">High</x-admin.badge>
                                    @endif
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-theme-xs text-gray-500">
                                    <span>{{ $task->type->label() }}</span>
                                    @if ($task->recurrence)
                                        <span class="inline-flex items-center gap-1" title="{{ $task->recurrence->label() }}"><x-icon name="refresh" class="size-3" /> {{ $task->recurrence->label() }}</span>
                                    @endif
                                    @if ($task->due_at)
                                        <span @class(['text-error-600' => ! $done && $task->due_at->isPast()])>· {{ $done ? 'Done '.$task->completed_at?->diffForHumans() : $task->due_at->format('D j M, g:i a') }}</span>
                                    @endif
                                    @if ($task->lead)
                                        <span>· <a href="{{ route('admin.leads.show', $task->lead) }}" wire:navigate class="text-navy-700 hover:underline dark:text-gray-300">{{ $task->lead->contact->name }} ({{ $task->lead->ref }})</a></span>
                                    @endif
                                    @if ($scope === 'team')
                                        <span>· {{ $task->assignee?->name ?? 'Unassigned' }}</span>
                                    @endif
                                </p>
                            </div>
                            <button type="button" wire:click="delete({{ $task->id }})" wire:confirm="Delete this task?" class="text-gray-300 opacity-0 transition group-hover:opacity-100 hover:text-error-600 focus:opacity-100" aria-label="Delete task">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        @empty
            <x-admin.card>
                <x-admin.empty icon="check-circle" :title="$show === 'done' ? 'Nothing completed yet' : 'All clear'" description="Tasks from leads, site visits and quotations appear here with their due times." />
            </x-admin.card>
        @endforelse
    </div>

    @if ($showCreate)
        <x-admin.modal name="showCreate" title="New task" submit="create">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-admin.field label="Task" for="title" class="sm:col-span-2">
                    <input id="title" type="text" wire:model="title" class="wx-input" placeholder="Send revised kitchen layout" autofocus>
                </x-admin.field>
                <x-admin.field label="Type" for="type">
                    <select id="type" wire:model="type" class="wx-input">
                        @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Priority" for="priority">
                    <select id="priority" wire:model="priority" class="wx-input">
                        @foreach ($priorities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Due" for="due">
                    <input id="due" type="datetime-local" wire:model="due" class="wx-input">
                </x-admin.field>
                <x-admin.field label="Assign to" for="assignee">
                    <select id="assignee" wire:model="assignee" class="wx-input" @disabled(! auth()->user()->can('leads.assign'))>
                        @foreach ($staff as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Repeats" for="recurrence" class="sm:col-span-2" hint="Completing a repeating task creates the next one automatically.">
                    <select id="recurrence" wire:model="recurrence" class="wx-input">
                        <option value="">Does not repeat</option>
                        @foreach ($recurrences as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field label="Lead reference (optional)" for="leadRef" class="sm:col-span-2" hint="e.g. L-2026-000123 — links the task to the lead's timeline.">
                    <input id="leadRef" type="text" wire:model="leadRef" class="wx-input" placeholder="L-2026-…">
                </x-admin.field>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showCreate', false)">Cancel</x-admin.button>
                <x-admin.button type="submit">Add task</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

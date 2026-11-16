<div>
    <x-admin.page-header title="Lead assignment" description="Rules are checked from top to bottom. The first rule that matches a new lead and has someone under their workload cap assigns it."
                         :breadcrumbs="['System' => null, 'Settings' => route('admin.settings'), 'Lead assignment' => null]">
        <x-slot:actions>
            <x-admin.button icon="plus" wire:click="openCreate">New rule</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="space-y-3">
        @forelse ($rules as $rule)
            <x-admin.card wire:key="rule-{{ $rule->id }}" @class(['opacity-60' => ! $rule->is_active])>
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-navy-50 text-theme-sm font-semibold text-navy-700 dark:bg-white/10 dark:text-white">{{ $loop->iteration }}</span>
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-gray-800 dark:text-white/90">
                                {{ $rule->name }}
                                @if (! $rule->is_active)<x-admin.badge color="gray">Paused</x-admin.badge>@endif
                                @if ($rule->working_hours_only)<x-admin.badge color="wood" :dot="false">Working hours only</x-admin.badge>@endif
                            </p>
                            <p class="mt-0.5 text-theme-sm text-gray-600 dark:text-gray-400">{{ $rule->summary() }}</p>
                            <p class="mt-1 text-theme-xs text-gray-500">
                                → {{ ($rule->user_ids ?? []) !== [] ? collect($rule->user_ids)->map(fn ($id) => ($staffNames[$id] ?? '#'.$id).' ('.($loads[$id] ?? 0).')')->implode(', ') : 'Everyone with role '.\Illuminate\Support\Str::headline((string) $rule->role).' ('.($roleCounts[$rule->role] ?? 0).')' }}
                                · {{ \App\Domain\Crm\Models\AssignmentRule::STRATEGIES[$rule->strategy] ?? $rule->strategy }}
                                @if ($rule->max_open_leads) · max {{ $rule->max_open_leads }} open leads each @endif
                                @if ($rule->lastAssigned) · last: {{ $rule->lastAssigned->name }} @endif
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" wire:click="move({{ $rule->id }}, -1)" @disabled($loop->first) class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-white/5" aria-label="Move up"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                        <button type="button" wire:click="move({{ $rule->id }}, 1)" @disabled($loop->last) class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-white/5" aria-label="Move down"><x-icon name="chevron-down" class="size-4" /></button>
                        <x-admin.button variant="secondary" size="sm" wire:click="toggle({{ $rule->id }})">{{ $rule->is_active ? 'Pause' : 'Resume' }}</x-admin.button>
                        <x-admin.button variant="secondary" size="sm" icon="pencil" wire:click="edit({{ $rule->id }})">Edit</x-admin.button>
                        <button type="button" wire:click="delete({{ $rule->id }})" wire:confirm="Delete the rule “{{ $rule->name }}”?" class="rounded-lg p-2 text-gray-400 hover:bg-error-50 hover:text-error-600" aria-label="Delete"><x-icon name="trash" class="size-4" /></button>
                    </div>
                </div>
            </x-admin.card>
        @empty
            <x-admin.card>
                <x-admin.empty icon="users" title="No rules yet" description="Without rules, each new lead goes to the active sales person with the fewest open leads (then the sales manager). Add rules to route by line, service, city/area or source.">
                    <x-admin.button icon="plus" wire:click="openCreate">Add the first rule</x-admin.button>
                </x-admin.empty>
            </x-admin.card>
        @endforelse

        <p class="px-1 text-theme-xs text-gray-500">If no rule matches, the lead goes to the active sales person with the fewest open leads, then the sales manager. Numbers in brackets are open leads today.</p>
    </div>

    @if ($showForm)
        <x-admin.modal name="showForm" :title="$editingId ? 'Edit rule' : 'New rule'" submit="save" width="max-w-2xl">
            <div class="space-y-5">
                <x-admin.field label="Rule name" for="rule.name">
                    <input id="rule.name" type="text" wire:model="rule.name" class="wx-input" placeholder="Project leads in DHA and Gulberg">
                </x-admin.field>

                <fieldset>
                    <legend class="wx-label">When the lead is… (leave a group empty for “any”)</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($lines as $value => $label)
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-theme-sm has-[:checked]:border-navy-900 has-[:checked]:bg-navy-50 dark:border-gray-700 dark:has-[:checked]:bg-white/10">
                                <input type="checkbox" wire:model="rule.business_lines" value="{{ $value }}" class="rounded border-gray-300"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-admin.field label="Services (comma separated)" for="rule.services" hint="e.g. turnkey, renovation">
                            <input id="rule.services" type="text" wire:model="rule.services" class="wx-input">
                        </x-admin.field>
                        <x-admin.field label="Cities or areas (comma separated)" for="rule.cities" hint="e.g. Islamabad, DHA, Bahria Town">
                            <input id="rule.cities" type="text" wire:model="rule.cities" class="wx-input">
                        </x-admin.field>
                    </div>
                    <details class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700" @if (($rule['sources'] ?? []) !== []) open @endif>
                        <summary class="cursor-pointer text-theme-sm font-medium text-gray-700 dark:text-gray-300">Sources {{ ($rule['sources'] ?? []) !== [] ? '('.count($rule['sources']).')' : '(any)' }}</summary>
                        <div class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                            @foreach ($sources as $value => $label)
                                <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="rule.sources" value="{{ $value }}" class="rounded border-gray-300"> {{ $label }}</label>
                            @endforeach
                        </div>
                    </details>
                </fieldset>

                <fieldset>
                    <legend class="wx-label">…assign it to</legend>
                    <div class="mt-2 flex gap-4 text-theme-sm text-gray-700 dark:text-gray-300">
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="rule.assign_to" value="role"> Everyone with a role</label>
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="rule.assign_to" value="people"> Chosen people</label>
                    </div>
                    @if (($rule['assign_to'] ?? 'role') === 'role')
                        <select wire:model="rule.role" class="wx-input mt-3" aria-label="Role">
                            @foreach ($roles as $key => $description)
                                <option value="{{ $key }}">{{ \Illuminate\Support\Str::headline($key) }} ({{ $roleCounts[$key] ?? 0 }} active)</option>
                            @endforeach
                        </select>
                    @else
                        <div class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                            @foreach ($staff as $member)
                                <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="rule.user_ids" value="{{ $member->id }}" class="rounded border-gray-300"> {{ $member->name }} <span class="text-theme-xs text-gray-400">({{ $loads[$member->id] ?? 0 }} open)</span></label>
                            @endforeach
                        </div>
                        @error('rule.user_ids') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    @endif
                </fieldset>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-admin.field label="How to choose" for="rule.strategy">
                        <select id="rule.strategy" wire:model="rule.strategy" class="wx-input">
                            @foreach (\App\Domain\Crm\Models\AssignmentRule::STRATEGIES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field label="Workload cap (optional)" for="rule.max_open_leads" hint="Skip people who already have this many open leads.">
                        <input id="rule.max_open_leads" type="number" min="1" wire:model="rule.max_open_leads" class="wx-input" placeholder="No cap">
                    </x-admin.field>
                </div>

                <div class="space-y-2 text-theme-sm text-gray-700 dark:text-gray-300">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="rule.working_hours_only" class="rounded border-gray-300"> Only during working hours (put an after-hours rule below it)</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="rule.is_active" class="rounded border-gray-300"> Active</label>
                </div>
            </div>
            <x-slot:footer>
                <x-admin.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="check">Save rule</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

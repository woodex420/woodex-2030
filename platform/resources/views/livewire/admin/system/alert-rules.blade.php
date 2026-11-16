<div>
    <x-admin.page-header title="Alert rules" description="Every alert the OS raises, and where it goes. Each event below ships with a default routing — add a rule to take an event over: who hears it, through which channels, and whether quiet hours hold. Delete the rule and the default returns."
                         :breadcrumbs="['System' => null, 'Alert rules' => null]" />

    <div class="mb-4">
        <x-admin.button icon="plus" wire:click="addRule">Add a rule</x-admin.button>
    </div>

    <div class="space-y-3">
        @foreach ($catalogue as $event)
            @php($eventRules = $rules->where('event', $event['key']))
            <x-admin.card :padding="false">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div class="min-w-0">
                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $event['label'] }} <span class="ml-1 font-mono text-theme-xs text-gray-400">{{ $event['key'] }}</span></p>
                        <p class="mt-0.5 text-theme-xs text-gray-500">{{ $event['description'] }}</p>
                        <p class="mt-1 text-theme-xs text-gray-400">Default: {{ $event['defaults'] }}</p>
                    </div>
                    <span @class(['rounded-full px-3 py-1 text-theme-xs font-medium' => true, 'bg-soft text-gray-500' => $eventRules->isEmpty(), 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $eventRules->isNotEmpty()])>
                        {{ $eventRules->isEmpty() ? 'default routing' : $eventRules->count().' rule(s)' }}
                    </span>
                </div>

                @if ($eventRules->isNotEmpty())
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($eventRules as $rule)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="text-theme-sm text-gray-800 dark:text-white/90">
                                        {{ $rule->name }}
                                        @if (! $rule->is_active)<span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs text-gray-500 dark:bg-white/5">paused</span>@endif
                                        @if ($rule->priority === 'critical')<span class="ml-1 rounded-full bg-error-50 px-2 py-0.5 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400">critical</span>@endif
                                    </p>
                                    <p class="mt-0.5 text-theme-xs text-gray-500">
                                        {{ collect($rule->recipients['roles'] ?? [])->map(fn ($r) => ucfirst($r))->implode(', ') ?: '—' }}
                                        @if (! empty($rule->recipients['users'])) · {{ $staff->whereIn('id', $rule->recipients['users'])->pluck('name')->implode(', ') }} @endif
                                        · {{ collect($rule->channels)->map(fn ($c) => ['database' => 'in-app', 'telegram' => 'Telegram', 'mail' => 'email'][$c] ?? $c)->implode(' + ') }}
                                        · {{ $rule->respect_quiet_hours ? 'respects quiet hours' : 'bypasses quiet hours' }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="toggleRule({{ $rule->id }})" class="wx-mini-btn">{{ $rule->is_active ? 'Pause' : 'Resume' }}</button>
                                    <button type="button" wire:click="editRule({{ $rule->id }})" class="wx-mini-btn">Edit</button>
                                    <button type="button" wire:click="removeRule({{ $rule->id }})" wire:confirm="Remove this rule? The event returns to its default routing." class="text-theme-sm text-error-600 hover:underline">Remove</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>
        @endforeach
    </div>

    @if ($showRuleForm)
        <x-admin.modal name="showRuleForm" :title="$ruleId !== null ? 'Edit the rule' : 'Add a rule'" description="The rule takes over this event's routing; delete it and the default returns." submit="save" width="max-w-2xl">
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-admin.field label="Event" for="event">
                        <select id="event" wire:model.live="event" class="wx-input">
                            @foreach ($catalogue as $event)
                                <option value="{{ $event['key'] }}">{{ $event['label'] }} ({{ $event['key'] }})</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field label="Rule name" for="name">
                        <input id="name" type="text" wire:model="name" class="wx-input" placeholder="Owners only, critical">
                    </x-admin.field>
                </div>

                <x-admin.field label="Who hears it — roles" hint="Everyone holding one of these roles, plus anyone named below.">
                    <div class="flex flex-wrap gap-3">
                        @foreach ($allRoles as $role)
                            <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" value="{{ $role }}" wire:model="roles" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ ucfirst($role) }}
                            </label>
                        @endforeach
                    </div>
                </x-admin.field>
                @error('roles')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror

                <x-admin.field label="Who hears it — named people" hint="Optional: specific teammates, role or no role.">
                    <select multiple size="4" wire:model.blur="users" class="wx-input">
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-admin.field>

                <x-admin.field label="Channels" hint="Telegram needs a linked chat; email needs an address. Picking none is not allowed.">
                    <div class="flex flex-wrap gap-3">
                        @foreach (['database' => 'In-app', 'telegram' => 'Telegram', 'mail' => 'Email'] as $channel => $label)
                            <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" value="{{ $channel }}" wire:model="channels" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </x-admin.field>
                @error('channels')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-admin.field label="Quiet hours" hint="Off: the alert reaches a muted chat at once.">
                        <label class="flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:model="respectQuietHours" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900">
                            Respect quiet hours
                        </label>
                    </x-admin.field>
                    <x-admin.field label="Priority" for="priority">
                        <select id="priority" wire:model="priority" class="wx-input">
                            <option value="normal">Normal</option>
                            <option value="critical">Critical — deliver now, even muted</option>
                        </select>
                    </x-admin.field>
                </div>
            </div>

            <x-slot:footer>
                <x-admin.button variant="secondary" type="button" wire:click="$set('showRuleForm', false)">Cancel</x-admin.button>
                <x-admin.button type="submit" icon="save">{{ $ruleId !== null ? 'Update rule' : 'Add rule' }}</x-admin.button>
            </x-slot:footer>
        </x-admin.modal>
    @endif
</div>

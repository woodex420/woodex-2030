<form wire:submit="save" class="space-y-6">
    <div class="flex flex-col gap-3 rounded-2xl border border-navy-100 bg-navy-50/60 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/[0.03]">
        <p class="text-theme-sm text-gray-700 dark:text-gray-300"><span class="font-medium">Who gets new leads?</span> Route by line, service, city/area and source, round robin or fewest open leads, with workload caps.</p>
        <x-admin.button variant="secondary" icon="users" :href="route('admin.assignment-rules')" wire:navigate>Lead assignment rules</x-admin.button>
    </div>

    <x-admin.card title="Response targets (SLA)" description="How fast a new enquiry must get its first call or message. Outside working hours and on holidays the timer starts at the next opening + 30 minutes.">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-admin.field label="Default target (minutes)" for="sla.default">
                <input id="sla.default" type="number" min="5" wire:model="sla.default" class="wx-input">
            </x-admin.field>
            <x-admin.field label="Escalate again after (minutes)" for="sla.escalation" hint="Still not contacted this long after the miss → final escalation roles (Notifications tab).">
                <input id="sla.escalation" type="number" min="5" wire:model="sla.escalation" class="wx-input">
            </x-admin.field>
        </div>
    </x-admin.card>

    <x-admin.card title="Lead score" description="Every lead gets 0–100 points from these rules. Leads at or above the hot threshold show a Hot badge and sort first.">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <x-admin.field label="Starting points" for="scoring.base"><input id="scoring.base" type="number" wire:model="scoring.base" class="wx-input"></x-admin.field>
            <x-admin.field label="Hot at" for="scoring.hot"><input id="scoring.hot" type="number" wire:model="scoring.hot" class="wx-input"></x-admin.field>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 text-theme-sm font-medium text-gray-800 dark:text-white/90">Budget</h3>
                <div class="space-y-2">
                    @foreach ($bands as $key => $label)
                        <label class="flex items-center justify-between gap-3 text-theme-sm text-gray-700 dark:text-gray-300">
                            <span>{{ $label }}</span>
                            <input type="number" wire:model="scoring.budget.{{ $key }}" class="wx-input w-24" aria-label="Points for {{ $label }}" placeholder="0">
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-theme-sm font-medium text-gray-800 dark:text-white/90">Property size</h3>
                <div class="space-y-2">
                    @foreach ($scoring['size'] as $i => $row)
                        <div class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300" wire:key="size-{{ $i }}">
                            <span>From</span>
                            <input type="number" wire:model="scoring.size.{{ $i }}.min_sqft" class="wx-input w-28" aria-label="Minimum square feet">
                            <span>sq ft</span>
                            <input type="number" wire:model="scoring.size.{{ $i }}.points" class="wx-input ml-auto w-24" aria-label="Points">
                        </div>
                    @endforeach
                    <p class="text-theme-xs text-gray-500">The largest matching size counts. 1 kanal = 4,500 sq ft; 1 marla = 225 sq ft.</p>
                </div>
            </div>
            <div>
                <h3 class="mb-2 text-theme-sm font-medium text-gray-800 dark:text-white/90">Business line and timeline</h3>
                <div class="space-y-2">
                    @foreach ($lines as $key => $label)
                        <label class="flex items-center justify-between gap-3 text-theme-sm text-gray-700 dark:text-gray-300">
                            <span>{{ $label }}</span>
                            <input type="number" wire:model="scoring.lines.{{ $key }}" class="wx-input w-24" placeholder="0">
                        </label>
                    @endforeach
                    @foreach ($timelines as $key => $label)
                        <label class="flex items-center justify-between gap-3 text-theme-sm text-gray-700 dark:text-gray-300">
                            <span>{{ $label }}</span>
                            <input type="number" wire:model="scoring.timelines.{{ $key }}" class="wx-input w-24" placeholder="0">
                        </label>
                    @endforeach
                    @foreach (['returning' => 'Returning client (lead type)', 'referral' => 'Referral (lead type)'] as $key => $label)
                        <label class="flex items-center justify-between gap-3 text-theme-sm text-gray-700 dark:text-gray-300">
                            <span>{{ $label }}</span>
                            <input type="number" wire:model="scoring.lead_types.{{ $key }}" class="wx-input w-24" placeholder="0">
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="space-y-4">
                <x-admin.field label="Services (one per line: service = points)" for="scoring.services">
                    <textarea id="scoring.services" wire:model="scoring.services" rows="5" class="wx-input font-mono text-theme-xs" placeholder="turnkey = 10"></textarea>
                </x-admin.field>
                <x-admin.field label="Cities or areas (one per line: name = points)" for="scoring.cities">
                    <textarea id="scoring.cities" wire:model="scoring.cities" rows="3" class="wx-input font-mono text-theme-xs" placeholder="dha = 5"></textarea>
                </x-admin.field>
            </div>
        </div>
    </x-admin.card>

    <x-admin.card title="Per source" description="Leave a target empty to use the default. Source points add to the score (the higher of source and lead type counts)." :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr><th class="px-5 py-3 font-medium">Source</th><th class="px-5 py-3 font-medium">Response target (min)</th><th class="px-5 py-3 font-medium">Score points</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($sources as $key => $label)
                        <tr wire:key="src-{{ $key }}">
                            <td class="px-5 py-2 text-gray-700 dark:text-gray-300">{{ $label }}</td>
                            <td class="px-5 py-2">
                                <input type="number" min="5" wire:model="sla.by_source.{{ $key }}" class="wx-input w-28" placeholder="{{ $sla['default'] }}" aria-label="Target for {{ $label }}">
                                @error('sla.by_source.'.$key) <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-5 py-2"><input type="number" wire:model="scoring.sources.{{ $key }}" class="wx-input w-24" placeholder="0" aria-label="Points for {{ $label }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <div class="flex justify-end"><x-admin.button type="submit" icon="check">Save lead rules</x-admin.button></div>
</form>

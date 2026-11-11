<div>
    <x-admin.page-header title="Lead tracking"
                         description="The CRM the way the follow-up clock sees it: event-driven, no invented states. A lead moves because the team acted or the client replied — overdue means the next action is past, stale means a week of silence with nothing planned.">
    </x-admin.page-header>

    <div class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($groups as $key => $summary)
            <button wire:click="showGroup('{{ $key }}')" wire:key="group-{{ $key }}"
                    class="rounded-xl border p-3 text-left transition {{ $key === $group ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30' : 'border-gray-200 hover:border-brand-300 dark:border-gray-800' }}">
                <p class="text-theme-sm font-medium">{{ $summary['label'] }}</p>
                <p class="text-xl font-semibold {{ in_array($key, ['overdue', 'stale', 'uncontacted'], true) && $summary['count'] > 0 ? 'text-error-600 dark:text-error-400' : '' }}">{{ $summary['count'] }}</p>
            </button>
        @endforeach
    </div>

    <x-admin.card :title="$groups[$group]['label']" :description="$groups[$group]['hint']">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm divide-y divide-gray-100 dark:divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Lead</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Client</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Owner</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Next action</th>
                        <th class="px-3 py-2.5 font-medium text-gray-500 dark:text-gray-400">Last activity</th>
                        <th class="px-3 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($active as $lead)
                        <tr wire:key="lead-{{ $lead->id }}">
                            <td class="px-3 py-2.5">
                                <a href="{{ route('admin.leads.show', $lead) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $lead->ref }}</a>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $lead->stage?->name }}</p>
                            </td>
                            <td class="px-3 py-2.5">{{ $lead->contact?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5">{{ $lead->owner?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                @if ($lead->next_action_at !== null)
                                    @php($next = $lead->next_action_at)
                                    <span class="{{ $next->isPast() ? 'font-medium text-error-600 dark:text-error-400' : ($next->isToday() ? 'font-medium text-warning-600 dark:text-warning-400' : '') }}">
                                        {{ $next->format('d M Y, H:i') }}
                                    </span>
                                    @if ($lead->next_action_type)
                                        <x-admin.badge color="info">{{ $lead->next_action_type->label() }}</x-admin.badge>
                                    @endif
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">nothing planned</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400">
                                {{ ($lead->last_activity_at ?? $lead->created_at)?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                @php($wa = $this->whatsapp($lead))
                                @if ($wa)
                                    <x-admin.button size="sm" variant="secondary" icon="whatsapp" href="{{ $wa }}" target="_blank" rel="noopener" class="text-success-700">WhatsApp</x-admin.button>
                                @endif
                                @if ($canManage)
                                    <x-admin.button size="sm" wire:click="startLog({{ $lead->id }})">Log follow-up</x-admin.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">Nothing in this group — that is good news.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    @if ($logLeadId !== 0)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:key="follow-up-modal">
            <x-admin.card class="w-full max-w-lg" title="Log follow-up">
                <form wire:submit="logFollowUp" class="space-y-4">
                    <div>
                        <label class="wx-label">What happened?</label>
                        <select wire:model="logType" class="wx-input">
                            @foreach (['call' => 'Call', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'meeting' => 'Meeting', 'note' => 'Note'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="wx-label">Note</label>
                        <textarea wire:model="logNote" class="wx-input" rows="3" maxlength="500" placeholder="e.g. Called — client reviewing with family, will decide by Friday"></textarea>
                        @error('logNote')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="wx-label">Next action</label>
                            <select wire:model="logNextType" class="wx-input">
                                @foreach (['call' => 'Call', 'whatsapp' => 'WhatsApp', 'visit' => 'Site visit', 'meeting' => 'Meeting', 'email' => 'Email'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="wx-label">When</label>
                            <input type="datetime-local" wire:model="logNextAt" class="wx-input">
                            @error('logNextAt')<p class="mt-1 text-theme-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">This logs on the lead's timeline, moves the next action and refreshes the board. Nothing is sent to the client — that stays a button you press.</p>
                    <div class="flex justify-end gap-2">
                        <x-admin.button variant="secondary" type="button" wire:click="$set('logLeadId', 0)">Cancel</x-admin.button>
                        <x-admin.button type="submit">Save follow-up</x-admin.button>
                    </div>
                </form>
            </x-admin.card>
        </div>
    @endif
</div>

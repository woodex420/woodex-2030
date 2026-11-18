    <x-admin.card title="Integration activity" description="Metadata for calls and MCP key activity. No credentials or message bodies." :padding="false" class="mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <select wire:model.live="logIntegration" class="wx-input h-9 w-auto text-theme-sm" aria-label="Integration">
                <option value="">All integrations</option>
                @foreach ($integrations as $name)<option value="{{ $name }}">{{ \Illuminate\Support\Str::headline($name) }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model.live="failedOnly" class="rounded border-gray-300"> Failed only</label>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-theme-sm">
                <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                    <tr><th class="px-5 py-3 font-medium">When</th><th class="px-5 py-3 font-medium">Integration</th><th class="px-5 py-3 font-medium">Action</th><th class="px-5 py-3 font-medium">Result</th><th class="px-5 py-3 text-right font-medium">Time</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="px-5 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" title="{{ $log->created_at?->format('j M Y, g:i:s a') }}">{{ $log->created_at?->diffForHumans() }}</td>
                            <td class="px-5 py-2.5">{{ \Illuminate\Support\Str::headline($log->integration) }}</td>
                            <td class="px-5 py-2.5 font-mono text-theme-xs">{{ $log->action }}</td>
                            <td class="px-5 py-2.5">
                                @if ($log->ok)
                                    <x-admin.badge color="success">OK{{ $log->status_code ? ' · '.$log->status_code : '' }}</x-admin.badge>
                                @else
                                    <x-admin.badge color="error">Failed{{ $log->status_code ? ' · '.$log->status_code : '' }}</x-admin.badge>
                                    <span class="mt-0.5 block max-w-md truncate text-theme-xs text-gray-500">{{ $log->error ?? ($log->response['description'] ?? '') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-2.5 text-right text-theme-xs text-gray-500 tabular-nums">{{ $log->duration_ms !== null ? $log->duration_ms.' ms' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-8 text-center text-theme-sm text-gray-500">No outbound calls logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links('components.admin.pagination') }}
    </x-admin.card>

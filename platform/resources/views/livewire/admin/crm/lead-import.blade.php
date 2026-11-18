<div>
    <x-admin.page-header title="Import a lead sheet"
                         description="Bring a historical enquiry sheet (CSV) into Leads. Nothing is written until the final step, and existing contacts are never changed."
                         :breadcrumbs="['CRM' => null, 'Leads' => route('admin.leads.index'), 'Import sheet' => null]">
        <x-slot:actions>
            <x-admin.button variant="secondary" icon="users" :href="route('admin.leads.index')" wire:navigate>Back to leads</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.card class="mb-5" title="One year · import / export" description="Export is a read-only snapshot of active CRM leads created in the selected UTC year, with current stage kept as reference. Archived leads/contacts and all financial data are excluded.">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="export-lead-year" class="wx-label">CSV year</label>
                <input id="export-lead-year" type="number" min="2000" max="{{ now()->year }}" wire:model.live="year" class="wx-input w-36">
            </div>
            <x-admin.button variant="secondary" icon="download" :href="route('admin.leads.export', ['year' => $year], false)" download>Export year CSV</x-admin.button>
            @if (request()->attributes->get('synthetic_direct_preview') === true)
                <x-admin.button variant="secondary" :href="asset('__sandbox/leads-demo-2026.csv')" download>Download 16-row 2026 demo CSV</x-admin.button>
                @if (is_string(config('preview.demo_sheet_url')) && preg_match('~\Ahttps://docs\.google\.com/spreadsheets/d/[a-zA-Z0-9_-]+/edit\z~', config('preview.demo_sheet_url')) === 1)
                    <x-admin.button variant="secondary" :href="config('preview.demo_sheet_url')" target="_blank" rel="noopener noreferrer">Open manual Drive snapshot</x-admin.button>
                @endif
            @endif
        </div>
        <p class="mt-3 text-theme-xs text-gray-500">CSV columns: Sr, Date, Name, Contact, Status. Contacts use the international 00 prefix. Keep all columns as plain text in Sheets, especially phone numbers and day/month/year dates. Formula-like names/stages are escaped in the export; stored records are unchanged. Imports still require mapping, a dry run and your final confirmation.</p>
        <p class="mt-2 text-theme-xs text-gray-500">Recommended Drive workflow: a dedicated one-year demo sheet, manual snapshot export and owner-reviewed CSV import. No background or two-way sync is enabled, and no old workbook is overwritten. Arena’s connected Drive is separate from the app’s Google Workspace connection.</p>
        <a href="{{ route('admin.system.connections', [], false) }}" class="mt-2 inline-block text-theme-sm underline">Google Workspace connection settings</a>
    </x-admin.card>

    <x-admin.card class="mb-5">
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <p class="text-theme-sm font-medium text-navy-800 dark:text-white">1 · Upload &amp; match</p>
                <p class="mt-1 text-theme-xs text-gray-500">Pick the CSV, then match your sheet's headings to Sr, Date, Name and Contact. Every other column travels with the lead as a note.</p>
            </div>
            <div>
                <p class="text-theme-sm font-medium text-navy-800 dark:text-white">2 · Dry run</p>
                <p class="mt-1 text-theme-xs text-gray-500">A read-only rehearsal. The report shows counts and original CSV record numbers only — never names or phone numbers — so you can review it safely.</p>
            </div>
            <div>
                <p class="text-theme-sm font-medium text-navy-800 dark:text-white">3 · Import</p>
                <p class="mt-1 text-theme-xs text-gray-500">New people become leads with their original dates. Phones that already belong to a contact are skipped, and rows without a usable phone or a date in the chosen year are held for review — never guessed.</p>
            </div>
        </div>
    </x-admin.card>

    <x-admin.card class="mb-5">
        <h2 class="mb-1 text-theme-base font-medium text-gray-900 dark:text-white">1 · Choose the sheet</h2>
        <p class="mb-4 text-theme-xs text-gray-500">UTF-8 CSV, up to 5 MB, 5,000 rows and 200 columns. From Google Sheets, download the current sheet as CSV; review each year separately. This does not connect or sync a Google account. Dates must look like 05/01/2025 (day/month/year).</p>
        <input type="file" wire:model="file" accept=".csv,text/csv,text/plain" class="wx-input h-auto py-2.5" aria-label="Lead sheet CSV">
        @error('file') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror
        <div wire:loading wire:target="file" class="mt-2 text-theme-xs text-gray-500">Reading the sheet…</div>
        @if ($columns !== [])
            <p class="mt-3 text-theme-sm text-gray-600 dark:text-gray-400">
                <span class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($rowCount) }}</span> rows ·
                <span class="font-medium text-gray-800 dark:text-gray-200">{{ count($columns) }}</span> columns found:
                {{ implode(', ', $columns) }}
            </p>
        @endif
    </x-admin.card>

    @if ($columns !== [])
        <x-admin.card class="mb-5">
            <h2 class="mb-1 text-theme-base font-medium text-gray-900 dark:text-white">2 · Match the columns</h2>
            <p class="mb-4 text-theme-xs text-gray-500">Headings are pre-matched where the sheet gives them away — check each one. Status is optional; it is kept as a note on the lead, not as a stage.</p>

            @if ($headingsForReview !== [])
                <div class="mb-4 rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm dark:bg-warning-500/10">
                    <p class="font-medium">Duplicate or blank headings need your review</p>
                    <p class="mt-1">Each has its original column number: {{ implode(', ', $headingsForReview) }}. Values are kept separately, never merged or overwritten. Choose the intended column below; unmatched columns remain notes.</p>
                    <label class="mt-3 flex items-start gap-2"><input type="checkbox" wire:model.live="headingsReviewed" class="mt-1">I checked the original sheet positions and reviewed the column mapping.</label>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ($canonical as $target)
                    <div>
                        <label class="wx-label" for="map-{{ $target }}">{{ $target }}{{ $target === 'Status' ? ' (optional)' : '' }}</label>
                        <select id="map-{{ $target }}" wire:model.live="map.{{ $target }}" class="wx-input">
                            <option value="">— not in sheet —</option>
                            @foreach ($columns as $column)
                                <option value="{{ $column }}">{{ $column }}</option>
                            @endforeach
                        </select>
                        @error('map.'.$target) <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            @error('map') <p class="mt-2 text-theme-xs text-error-600">{{ $message }}</p> @enderror

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="wx-label" for="import-year">Sheet year</label>
                    <input id="import-year" type="number" wire:model.live="year" min="2000" max="{{ $currentYear }}" class="wx-input">
                    <p class="mt-1 text-theme-xs text-gray-500">Only rows dated in this year import; the rest are held for review.</p>
                    @error('year') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="wx-label" for="import-batch">Batch name</label>
                    <input id="import-batch" type="text" wire:model.live="batch" class="wx-input" placeholder="jan-2025-leads">
                    <p class="mt-1 text-theme-xs text-gray-500">Re-running the same batch is safe: unchanged rows are recognised and skipped.</p>
                    @error('batch') <p class="mt-1 text-theme-xs text-error-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-admin.card>

        <x-admin.card class="mb-5" title="Review source years — no import" description="Counts from the matched Date column only. No database comparison, date changes or application records are written. Run again after changing the mapping or selected year.">
            <x-admin.button variant="secondary" icon="search" wire:click="reviewSourceDates" wire:loading.attr="disabled" wire:target="reviewSourceDates">Review source dates</x-admin.button>
            @if ($sourceDateReview !== null)
                @if (! $sourceDateReview['mapped'])
                    <p class="mt-3 text-theme-sm text-warning-700">Choose the Date column first. {{ $sourceDateReview['unmapped'] }} rows have not been assigned a source date.</p>
                @else
                    <div class="mt-4 flex flex-wrap gap-2" aria-label="Source year counts">
                        @foreach ($sourceDateReview['years'] as $sourceYear => $count)
                            <x-admin.badge color="gray">{{ $sourceYear }} · {{ $count }} dates</x-admin.badge>
                        @endforeach
                    </div>
                    <p class="mt-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $sourceDateReview['selected'] }} non-future dates in selected year {{ $year }} · {{ $sourceDateReview['other'] }} in other years · {{ $sourceDateReview['missing'] }} missing · {{ $sourceDateReview['invalid'] }} invalid · {{ $sourceDateReview['future'] }} future.</p>
                    <p class="mt-2 text-theme-xs text-gray-500">Keep 2025 and 2026 separate. Do not assign a year to undated rows or infer an opportunity from a contact/reference list. These are date counts, not approved or eligible import counts; duplicate identities and the existing database still need review. Dates must be day/month/year.</p>
                @endif
            @endif
        </x-admin.card>

        <x-admin.card>
            <h2 class="mb-1 text-theme-base font-medium text-gray-900 dark:text-white">3 · Dry run, then import</h2>
            <p class="mb-4 text-theme-xs text-gray-500">The dry run writes nothing. Changing any match, the year or the batch clears the report.</p>

            @error('import') <p class="mb-3 rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/15 dark:text-error-500">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2">
                <x-admin.button variant="secondary" icon="search" wire:click="dryRun" wire:loading.attr="disabled" wire:target="dryRun,apply">Run dry run</x-admin.button>
                @if ($report !== null && ! $report['apply'] && ! $applied)
                    <x-admin.button icon="upload" wire:click="apply" wire:confirm="Import the ready rows from this sheet now?" wire:loading.attr="disabled" wire:target="dryRun,apply">
                        Import {{ $report['candidates'] }} ready {{ str('lead')->plural($report['candidates']) }}
                    </x-admin.button>
                @endif
            </div>

            @if ($report !== null)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @if ($report['apply'])
                        <x-admin.stat label="Imported" :value="$report['imported']" icon="check" />
                    @else
                        <x-admin.stat label="Ready to import" :value="$report['candidates']" icon="check" />
                    @endif
                    <x-admin.stat label="Already a contact" :value="$report['existing']" icon="users" />
                    <x-admin.stat label="Already imported" :value="$report['repeated']" icon="clock" />
                    <x-admin.stat label="Needs review" :value="$report['review']" icon="alert" />
                </div>

                @if ($report['groups'] !== [])
                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full text-left text-theme-sm">
                            <thead class="border-b border-gray-100 text-theme-xs tracking-wide text-gray-500 uppercase dark:border-gray-800">
                                <tr>
                                    <th class="px-3 py-2.5 font-medium">Outcome</th>
                                    <th class="px-3 py-2.5 font-medium">Why</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Rows</th>
                                    <th class="px-3 py-2.5 font-medium">Source CSV record numbers</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($report['groups'] as $group)
                                    <tr wire:key="group-{{ $group['outcome'] }}-{{ $group['reason'] }}">
                                        <td class="px-3 py-2.5 whitespace-nowrap">
                                            @if ($group['outcome'] === 'candidates')
                                                <x-admin.badge color="success">{{ $report['apply'] ? 'Imported' : 'Ready' }}</x-admin.badge>
                                            @elseif ($group['outcome'] === 'existing')
                                                <x-admin.badge color="navy">Already a contact</x-admin.badge>
                                            @elseif ($group['outcome'] === 'repeated')
                                                <x-admin.badge color="warning">Already imported</x-admin.badge>
                                            @else
                                                <x-admin.badge color="error">Needs review</x-admin.badge>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-gray-600 dark:text-gray-400">{{ $group['label'] }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums font-medium">{{ $group['count'] }}</td>
                                        <td class="px-3 py-2.5 text-theme-xs text-gray-500">
                                            {{ implode(', ', $group['rows']) }}@if ($group['count'] > count($group['rows'])) … @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($applied)
                    <div class="mt-5 flex flex-wrap items-center gap-2">
                        <x-admin.button icon="users" :href="route('admin.leads.index', ['source' => 'import'])" wire:navigate>View imported leads</x-admin.button>
                        <x-admin.button variant="secondary" wire:click="startOver">Import another sheet</x-admin.button>
                    </div>
                @endif
            @endif
        </x-admin.card>
    @endif
</div>

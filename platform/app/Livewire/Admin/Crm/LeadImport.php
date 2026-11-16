<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\System\Import\LeadCsvImporter;
use App\Domain\System\Import\LeadSheetDateReview;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Historical lead-sheet import (Slice V): the tested {@see LeadCsvImporter} behind a screen —
 * upload, match the sheet's own column headings to the five the importer reads, dry-run a
 * privacy-safe report (counts and row numbers only, never names or phones), then apply.
 * Owner-only, exactly like the importer itself.
 */
#[Layout('layouts::admin')]
#[Title('Import a lead sheet')]
class LeadImport extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    /** @var list<string> canonical headings the importer reads */
    public const CANONICAL = ['Sr', 'Date', 'Name', 'Contact', 'Status'];

    /** @var array<string, string> importer reason code → plain words */
    private const REASONS = [
        'new_historical_contact_status_retained_for_review' => 'New contact — imports as a lead, the sheet status travels as a note for review.',
        'previously_imported' => 'Already imported in this batch — skipped.',
        'source_row_changed_requires_review' => 'This row changed since it was imported — held for review.',
        'existing_or_archived_contact_untouched' => 'Phone already belongs to a contact — left untouched.',
        'duplicate_phone_in_sheet' => 'The same phone appears earlier in this sheet.',
        'invalid_or_missing_phone' => 'No usable phone number.',
        'missing_or_duplicate_source_id' => 'Sr number missing, not a number, or repeated.',
        'missing_invalid_or_wrong_year_date' => 'Date missing, unreadable, or not in the chosen year.',
        'missing_or_overlong_contact_name' => 'Name missing or too long.',
    ];

    /** @var array<string, list<string>> canonical heading → normalised sheet aliases */
    private const ALIASES = [
        'Sr' => ['sr', 'sr no', 's no', 'sno', 'serial', 'serial no', 'no', 'id'],
        'Date' => ['date', 'lead date', 'enquiry date', 'inquiry date', 'received', 'created', 'created on'],
        'Name' => ['name', 'customer', 'customer name', 'client', 'client name', 'lead name', 'contact name', 'person', 'full name'],
        'Contact' => ['contact', 'contact no', 'phone', 'phone number', 'mobile', 'mobile number', 'mobile no', 'cell', 'cell number', 'whatsapp', 'whatsapp number', 'number'],
        'Status' => ['status', 'lead status', 'stage', 'lead stage'],
    ];

    public ?UploadedFile $file = null;

    /** @var list<string> headings found in the uploaded sheet */
    public array $columns = [];

    /** @var list<string> Duplicate or blank headings, labelled by their original column position. */
    public array $headingsForReview = [];

    public bool $headingsReviewed = false;

    /** @var array{mapped: bool, years: array<int, int>, missing: int, invalid: int, future: int, selected: int, other: int, unmapped: int}|null */
    #[Locked]
    public ?array $sourceDateReview = null;

    /** @var array<string, string> canonical heading → sheet column ('' = not matched) */
    public array $map = ['Sr' => '', 'Date' => '', 'Name' => '', 'Contact' => '', 'Status' => ''];

    public int $rowCount = 0;

    public int $year;

    public string $batch = '';

    /**
     * Aggregated, privacy-safe report (counts + grouped reasons with sample row numbers).
     *
     * @var array{apply: bool, source_rows: int, candidates: int, imported: int, existing: int, repeated: int, review: int, groups: list<array{outcome: string, reason: string, label: string, count: int, rows: list<int>}>}|null
     */
    public ?array $report = null;

    public bool $applied = false;

    /** Stored upload token (storage/app/private/lead-imports/<token>.csv). */
    public ?string $stored = null;

    public function mount(): void
    {
        abort_unless($this->user()->hasRole('owner'), 403, 'Only the owner account can import historical lead sheets.');

        $this->year = (int) now()->format('Y');
    }

    public function updatedFile(): void
    {
        $this->resetRun();
        $this->reset('headingsForReview', 'headingsReviewed');
        $this->columns = [];
        $this->map = ['Sr' => '', 'Date' => '', 'Name' => '', 'Contact' => '', 'Status' => ''];
        $this->rowCount = 0;
        $this->resetValidation();

        if ($this->file === null) {
            return;
        }

        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], ['file.max' => 'The sheet must be smaller than 5 MB.']);

        try {
            $sheet = $this->readSheet($this->file->getRealPath());
        } catch (ValidationException $exception) {
            $this->addError('file', $exception->validator->errors()->first('import') ?? 'The sheet could not be read.');
            $this->file = null;

            return;
        }

        $token = Str::random(24);
        Storage::disk('local')->putFileAs('lead-imports', $this->file, $token.'.csv');
        $this->stored = $token;
        $this->columns = $sheet['header'];
        $this->headingsForReview = $sheet['review'];
        $this->rowCount = count($sheet['rows']);
        $this->map = $this->guessMap($sheet['header']);
        $this->prefillBatch($sheet['rows']);
    }

    public function updatedMap(): void
    {
        $this->resetRun();
    }

    public function updatedYear(): void
    {
        $this->resetRun();
    }

    public function updatedBatch(): void
    {
        $this->resetRun();
    }

    /** Inventory only; never calls the importer, database or a provider. */
    public function reviewSourceDates(LeadSheetDateReview $review): void
    {
        abort_unless($this->user()->is_active && $this->user()->hasRole('owner'), 403);
        $this->sourceDateReview = null;
        $this->resetValidation('file');
        $original = $this->storedPath();
        if ($original === null) {
            $this->addError('file', 'The uploaded sheet is no longer there — upload it again.');

            return;
        }
        try {
            $sheet = $this->readSheet($original);
            $column = $this->map['Date'] ?? '';
            if ($column !== '' && ! in_array($column, $sheet['header'], true)) {
                throw ValidationException::withMessages(['import' => 'The matched Date column is not in this sheet.']);
            }
            $this->sourceDateReview = $review->handle($sheet['rows'], $column, $this->year, now()->toDateTimeImmutable());
        } catch (ValidationException $exception) {
            $this->addError('file', $exception->validator->errors()->first('import'));
        }
    }

    public function dryRun(): void
    {
        $this->run(false);
    }

    public function apply(): void
    {
        if ($this->report === null || $this->applied) {
            return;
        }

        $this->run(true);
    }

    public function startOver(): void
    {
        $this->deleteStored();
        $this->reset('file', 'columns', 'rowCount', 'headingsForReview', 'headingsReviewed');
        $this->map = ['Sr' => '', 'Date' => '', 'Name' => '', 'Contact' => '', 'Status' => ''];
        $this->resetRun();
    }

    private function run(bool $apply): void
    {
        abort_unless($this->user()->hasRole('owner'), 403, 'Only the owner account can import historical lead sheets.');

        $this->validate([
            'map.Sr' => ['required', 'string'],
            'map.Date' => ['required', 'string'],
            'map.Name' => ['required', 'string'],
            'map.Contact' => ['required', 'string'],
            'map.Status' => ['nullable', 'string'],
            'year' => ['required', 'integer', 'min:2000', 'max:'.now()->format('Y')],
            'batch' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/D'],
        ], [
            'map.Sr.required' => 'Match the Sr (serial) column.',
            'map.Date.required' => 'Match the Date column.',
            'map.Name.required' => 'Match the Name column.',
            'map.Contact.required' => 'Match the Contact (phone) column.',
            'batch.regex' => 'Lowercase letters, digits, dashes and underscores only.',
        ]);

        $picked = array_filter($this->map);
        if (count(array_unique($picked)) !== count($picked)) {
            $this->addError('map', 'Each sheet column can only be matched once.');

            return;
        }

        $original = $this->storedPath();
        if ($original === null) {
            $this->addError('file', 'The uploaded sheet is no longer there — upload it again.');

            return;
        }

        $canonical = null;
        try {
            $canonical = $this->buildCanonical($original);
            $report = app(LeadCsvImporter::class)->handle($canonical['path'], $this->user(), $apply, trim($this->batch), (int) $this->year);
            // Canonical records omit blank rows. Show original CSV record positions in the review.
            foreach ($report['rows'] as &$row) {
                $row['row'] = $canonical['sourceRows'][$row['row'] - 2];
            }
            unset($row);
        } catch (ValidationException $exception) {
            $this->addError('import', $exception->validator->errors()->first('import') ?? 'The sheet could not be imported.');

            return;
        } finally {
            if ($canonical !== null) {
                Storage::disk('local')->delete('lead-imports/'.basename($canonical['path']));
            }
        }

        $this->report = $this->summarise($report);
        $this->applied = $apply;

        if ($apply) {
            $this->deleteStored();
            $this->toast($report['imported'].' '.str('lead')->plural($report['imported']).' imported from '.$this->batch.'.');
        }
    }

    /**
     * Reads the sheet the same way the importer does (BOM-tolerant, blank lines skipped).
     *
     * @return array{header: list<string>, rows: list<array<string, string>>, review: list<string>, sourceRows: list<int>}
     */
    private function readSheet(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5_000_000) {
            throw ValidationException::withMessages(['import' => 'Supply a readable UTF-8 CSV smaller than 5 MB.']);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['import' => 'The CSV could not be opened.']);
        }

        $header = null;
        $rows = [];
        $review = [];
        $sourceRows = [];
        $record = 0;
        try {
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $record++;
                $values = array_map(fn (?string $value): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '')), $values);
                if (! array_filter($values, fn (string $value): bool => $value !== '')) {
                    continue;
                }
                if ($header === null) {
                    if (count($values) > 200) {
                        throw ValidationException::withMessages(['import' => 'Sheets are limited to 200 columns.']);
                    }
                    $normalised = array_map(fn (string $value): string => mb_strtolower($value), $values);
                    $counts = array_count_values($normalised);
                    $reserved = $normalised;
                    $header = [];
                    foreach ($values as $position => $value) {
                        if ($value === '' || $counts[$normalised[$position]] > 1) {
                            $label = ($value === '' ? 'Unnamed' : $value).' [column '.($position + 1).']';
                            while (in_array(mb_strtolower($label), $reserved, true)) {
                                $label .= ' [review]';
                            }
                            $reserved[] = mb_strtolower($label);
                            $review[] = $label;
                            $header[] = $label;
                        } else {
                            $header[] = $value;
                        }
                    }

                    continue;
                }
                if (count($header) !== count($values)) {
                    throw ValidationException::withMessages(['import' => 'The CSV contains rows with the wrong number of columns.']);
                }
                if (count($rows) >= 5000) {
                    throw ValidationException::withMessages(['import' => 'Sheets are limited to 5,000 rows — split the file and import in batches.']);
                }
                $rows[] = array_combine($header, $values);
                $sourceRows[] = $record;
            }
        } finally {
            fclose($handle);
        }

        if ($header === null || $rows === []) {
            throw ValidationException::withMessages(['import' => 'No headings and data rows were found in this sheet.']);
        }

        return ['header' => $header, 'rows' => $rows, 'review' => $review, 'sourceRows' => $sourceRows];
    }

    /**
     * @param  list<string>  $header
     * @return array<string, string>
     */
    private function guessMap(array $header): array
    {
        $normalised = [];
        foreach ($header as $column) {
            $normalised[$column] = trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($column)));
        }

        $map = ['Sr' => '', 'Date' => '', 'Name' => '', 'Contact' => '', 'Status' => ''];
        $claimed = [];
        foreach (self::ALIASES as $canonical => $aliases) {
            foreach ($header as $column) {
                if (! isset($claimed[$column]) && in_array($normalised[$column], $aliases, true)) {
                    $map[$canonical] = $column;
                    $claimed[$column] = true;
                    break;
                }
            }
        }

        return $map;
    }

    /** @param list<array<string, string>> $rows */
    private function prefillBatch(array $rows): void
    {
        $dateColumn = $this->map['Date'];
        if ($dateColumn !== '') {
            foreach ($rows as $row) {
                if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D', $row[$dateColumn] ?? '', $m) === 1) {
                    $this->year = (int) $m[3];
                    $month = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'][(int) $m[2] - 1] ?? null;
                    $this->batch = $month === null ? 'sheet-'.$m[3] : $month.'-'.$m[3].'-leads';

                    return;
                }
            }
        }

        $this->year = (int) now()->format('Y');
        $this->batch = 'sheet-'.$this->year;
    }

    /**
     * Writes the canonical CSV; unmatched columns stay separate notes.
     *
     * @return array{path: string, sourceRows: list<int>}
     */
    private function buildCanonical(string $original): array
    {
        $sheet = $this->readSheet($original);
        // Re-read the stored source, not the browser's mutable display metadata.
        if ($sheet['review'] !== [] && ! $this->headingsReviewed) {
            throw ValidationException::withMessages(['import' => 'Review the position-labelled headings, choose the intended columns, and confirm the heading review.']);
        }
        if (array_diff(array_filter($this->map), $sheet['header']) !== []) {
            throw ValidationException::withMessages(['import' => 'A matched column is not in this sheet. Match the columns again.']);
        }

        $consumed = array_values(array_filter($this->map));
        $extras = [];
        foreach ($sheet['header'] as $column) {
            if (in_array($column, $consumed, true)) {
                continue;
            }
            $label = in_array($column, self::CANONICAL, true) ? 'Sheet '.$column : $column;
            while (in_array($label, [...self::CANONICAL, ...array_values($extras)], true)) {
                $label .= ' [extra]';
            }
            $extras[$column] = $label;
        }

        $handle = tmpfile();
        if ($handle === false) {
            throw ValidationException::withMessages(['import' => 'The server could not prepare the import file.']);
        }

        fputcsv($handle, [...self::CANONICAL, ...array_values($extras)], escape: '');
        foreach ($sheet['rows'] as $row) {
            $line = [];
            foreach (self::CANONICAL as $canonical) {
                $source = $this->map[$canonical] ?? '';
                $line[] = $source !== '' ? ($row[$source] ?? '') : '';
            }
            foreach (array_keys($extras) as $column) {
                $line[] = $row[$column] ?? '';
            }
            fputcsv($handle, $line, escape: '');
        }

        $path = stream_get_meta_data($handle)['uri'];
        rewind($handle);
        $canonical = Storage::disk('local')->path('lead-imports').'/canonical-'.Str::random(16).'.csv';
        Storage::disk('local')->makeDirectory('lead-imports');
        file_put_contents($canonical, stream_get_contents($handle));
        fclose($handle);
        if ($path !== $canonical && is_file((string) $path)) {
            unlink((string) $path);
        }

        return ['path' => $canonical, 'sourceRows' => $sheet['sourceRows']];
    }

    /**
     * @param  array{apply: bool, source_rows: int, candidates: int, imported: int, existing: int, repeated: int, review: int, rows: list<array{row: int, outcome: string, reason: string}>}  $report
     * @return array{apply: bool, source_rows: int, candidates: int, imported: int, existing: int, repeated: int, review: int, groups: list<array{outcome: string, reason: string, label: string, count: int, rows: list<int>}>}
     */
    private function summarise(array $report): array
    {
        $groups = [];
        foreach ($report['rows'] as $row) {
            $key = $row['outcome'].'|'.$row['reason'];
            $groups[$key] ??= [
                'outcome' => $row['outcome'],
                'reason' => $row['reason'],
                'label' => self::REASONS[$row['reason']] ?? Str::headline(str_replace('_', ' ', $row['reason'])),
                'count' => 0,
                'rows' => [],
            ];
            $groups[$key]['count']++;
            if (count($groups[$key]['rows']) < 12) {
                $groups[$key]['rows'][] = $row['row'];
            }
        }

        unset($report['rows']);
        $report['groups'] = array_values($groups);

        return $report;
    }

    private function storedPath(): ?string
    {
        if ($this->stored === null || preg_match('/^[A-Za-z0-9]{24}$/D', $this->stored) !== 1) {
            return null;
        }

        $path = Storage::disk('local')->path('lead-imports/'.$this->stored.'.csv');

        return is_file($path) ? $path : null;
    }

    private function deleteStored(): void
    {
        if ($this->stored !== null && preg_match('/^[A-Za-z0-9]{24}$/D', $this->stored) === 1) {
            Storage::disk('local')->delete('lead-imports/'.$this->stored.'.csv');
        }
        $this->stored = null;

    }

    private function resetRun(): void
    {
        $this->report = null;
        $this->applied = false;
        $this->sourceDateReview = null;
    }

    public function render(): View
    {
        return view('livewire.admin.crm.lead-import', [
            'canonical' => self::CANONICAL,
            'currentYear' => (int) now()->format('Y'),
        ]);
    }
}

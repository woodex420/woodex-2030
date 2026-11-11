<?php

declare(strict_types=1);

namespace App\Domain\System\Import;

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Contact;
use App\Domain\System\Models\LegacyRef;
use App\Models\User;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Historical lead sheets only: new contacts, no financial or outbound-message inference. */
final class LeadCsvImporter
{
    private const REQUIRED = ['Sr', 'Date', 'Name', 'Contact', 'Status'];

    public function __construct(private readonly CaptureLead $capture) {}

    /**
     * @return array{apply: bool, source_rows: int, candidates: int, imported: int, existing: int, repeated: int, review: int, rows: list<array{row: int, outcome: string, reason: string}>}
     */
    public function handle(string $path, User $by, bool $apply = false, string $batch = 'jan-2025-leads', int $year = 2025): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D', $batch) || $year < 2000 || $year > (int) now()->format('Y')) {
            throw ValidationException::withMessages(['import' => 'Use a valid batch key and historical year.']);
        }
        $rows = $this->read($path);

        return DB::transaction(function () use ($rows, $by, $apply, $batch, $year): array {
            $actor = User::query()->whereKey($by->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->hasRole('owner'), 403);
            $report = ['apply' => $apply, 'source_rows' => count($rows), 'candidates' => 0, 'imported' => 0, 'existing' => 0, 'repeated' => 0, 'review' => 0, 'rows' => []];
            $seenIds = [];
            $seenPhones = [];
            $existingPhones = [];
            foreach (Contact::withTrashed()->get(['phone', 'alt_phone', 'whatsapp']) as $contact) {
                foreach ([$contact->phone, $contact->alt_phone, $contact->whatsapp] as $number) {
                    if (($normalized = Phone::normalize($number)) !== null) {
                        $existingPhones[$normalized] = true;
                    }
                }
            }

            foreach ($rows as $entry) {
                $fields = $entry['fields'];
                $sr = $fields['Sr'];
                $checksum = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
                $key = hash('sha256', $batch.'|'.$sr);
                $ref = LegacyRef::query()->where('legacy_table', 'lead_csv_'.$year)->where('legacy_id', $key)->first();
                $outcome = 'review';
                $reason = 'invalid_or_missing_phone';
                $phone = preg_match('/^[+0-9() .-]+$/D', $fields['Contact']) ? Phone::normalize($fields['Contact']) : null;
                $date = $this->date($fields['Date'], $year);
                if (! preg_match('/^[1-9][0-9]*$/D', $sr) || isset($seenIds[$sr])) {
                    $reason = 'missing_or_duplicate_source_id';
                } elseif ($ref !== null) {
                    $outcome = hash_equals((string) $ref->checksum, $checksum) ? 'repeated' : 'review';
                    $reason = $outcome === 'repeated' ? 'previously_imported' : 'source_row_changed_requires_review';
                } elseif ($phone === null) {
                    // Intentionally do not guess names, phone splits, dates or payments from notes.
                } elseif (isset($existingPhones[$phone])) {
                    $outcome = 'existing';
                    $reason = 'existing_or_archived_contact_untouched';
                } elseif (isset($seenPhones[$phone])) {
                    $outcome = 'existing';
                    $reason = 'duplicate_phone_in_sheet';
                } elseif ($date === null) {
                    $reason = 'missing_invalid_or_wrong_year_date';
                } elseif ($fields['Name'] === '' || mb_strlen($fields['Name']) > 160) {
                    $reason = 'missing_or_overlong_contact_name';
                } else {
                    $outcome = 'candidates';
                    $reason = 'new_historical_contact_status_retained_for_review';
                    $seenPhones[$phone] = true;
                    if ($apply) {
                        $message = collect($fields)->except(['Sr', 'Date', 'Name', 'Contact'])
                            ->filter(fn (string $value): bool => $value !== '')
                            ->map(fn (string $value, string $label): string => $label.': '.$value)->implode("\n");
                        $result = $this->capture->handle(new LeadInput(
                            name: $fields['Name'], phone: $phone, email: null, source: LeadSource::Import,
                            message: Str::limit($message, 10000, ''), sourceDetail: 'Historical '.$year.' lead sheet',
                            meta: ['historical_import' => ['batch' => $batch, 'source_row' => $sr, 'date_precision' => 'day', 'imported_at' => now()->toIso8601String(), 'needs_review' => true, 'fields' => $fields]],
                            historicalAt: $date,
                        ));
                        LegacyRef::query()->create([
                            'legacy_table' => 'lead_csv_'.$year, 'legacy_id' => $key,
                            'model_type' => 'lead', 'model_id' => $result->lead->id,
                            'checksum' => $checksum, 'imported_at' => now(),
                        ]);
                        $report['imported']++;
                        $existingPhones[$phone] = true;
                    }
                }
                $seenIds[$sr] = true;
                $report[$outcome]++;
                $report['rows'][] = ['row' => $entry['row'], 'outcome' => $outcome, 'reason' => $reason];
            }

            return $report;
        });
    }

    private function date(string $value, int $year): ?CarbonImmutable
    {
        if (! preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!d/m/Y', $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return $date !== null && $date->format('d/m/Y') === $value && $date->year === $year && ! $date->isFuture()
            ? $date->utc() : null;
    }

    /** @return list<array{row: int, fields: array<string, string>}> */
    private function read(string $path): array
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
        $line = 0;
        try {
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                $values = array_map(fn (?string $value): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '')), $values);
                if (! array_filter($values, fn (string $value): bool => $value !== '')) {
                    continue;
                }
                if ($header === null) {
                    if (array_diff(self::REQUIRED, $values) === []) {
                        if (count(array_unique($values)) !== count($values)) {
                            throw ValidationException::withMessages(['import' => 'Duplicate column headings require review.']);
                        }
                        $header = $values;
                    }

                    continue;
                }
                if (count($header) !== count($values) || count($rows) >= 5000 || collect($values)->contains(fn (string $v): bool => mb_strlen($v) > 20000)) {
                    throw ValidationException::withMessages(['import' => 'The CSV contains malformed or oversized rows.']);
                }
                $rows[] = ['row' => $line, 'fields' => array_combine($header, $values)];
            }
        } finally {
            fclose($handle);
        }
        if ($header === null || $rows === []) {
            throw ValidationException::withMessages(['import' => 'The expected lead headings and data rows were not found.']);
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\System\Import;

use DateTimeImmutable;
use DateTimeZone;

/** Source-only date inventory. No database access, writes, date repair or import decisions. */
final class LeadSheetDateReview
{
    /**
     * @param  list<array<string, string>>  $rows
     * @return array{mapped: bool, years: array<int, int>, missing: int, invalid: int, future: int, selected: int, other: int, unmapped: int}
     */
    public function handle(array $rows, string $dateColumn, int $selectedYear, DateTimeImmutable $today): array
    {
        $result = ['mapped' => $dateColumn !== '', 'years' => [], 'missing' => 0, 'invalid' => 0, 'future' => 0, 'selected' => 0, 'other' => 0, 'unmapped' => 0];
        $today = $today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        foreach ($rows as $row) {
            if ($dateColumn === '' || ! array_key_exists($dateColumn, $row)) {
                $result['unmapped']++;

                continue;
            }
            $value = trim($row[$dateColumn]);
            if ($value === '') {
                $result['missing']++;

                continue;
            }
            $date = preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', $value) === 1
                ? DateTimeImmutable::createFromFormat('!d/m/Y', $value, new DateTimeZone('UTC')) : false;
            if ($date === false || $date->format('d/m/Y') !== $value) {
                $result['invalid']++;

                continue;
            }
            $year = (int) $date->format('Y');
            $result['years'][$year] = ($result['years'][$year] ?? 0) + 1;
            if ($date > $today) {
                $result['future']++;
            } elseif ($year === $selectedYear) {
                $result['selected']++;
            } else {
                $result['other']++;
            }
        }
        ksort($result['years']);

        return $result;
    }
}

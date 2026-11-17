<?php

declare(strict_types=1);

use App\Domain\System\Import\LeadSheetDateReview;

it('counts source years without guessing dates or exposing row values', function () {
    $rows = [
        ['Date' => '05/01/2025', 'Name' => 'Synthetic private sentinel'],
        ['Date' => '07/03/2026'],
        ['Date' => ''],
        ['Date' => '31/02/2025'],
        ['Date' => '2025-01-05'],
        ['Date' => '04/11/2026'],
    ];
    $original = $rows;
    $result = (new LeadSheetDateReview)->handle($rows, 'Date', 2025, new DateTimeImmutable('2026-11-03T12:00:00Z'));
    expect($result)->toBe([
        'mapped' => true, 'years' => [2025 => 1, 2026 => 2], 'missing' => 1,
        'invalid' => 2, 'future' => 1, 'selected' => 1, 'other' => 1, 'unmapped' => 0,
    ])->and($rows)->toBe($original)
        ->and(json_encode($result))->not->toContain('Synthetic private sentinel', '05/01/2025');
});

it('holds unmapped date columns rather than assigning a year', function () {
    $result = (new LeadSheetDateReview)->handle([['Date' => '05/01/2025']], '', 2026, new DateTimeImmutable('2026-11-03'));
    expect($result['mapped'])->toBeFalse()->and($result['unmapped'])->toBe(1)->and($result['years'])->toBe([]);
});

it('uses strict calendar dates including leap days and a UTC cutoff', function () {
    $result = (new LeadSheetDateReview)->handle([
        ['D' => '29/02/2024'], ['D' => '29/02/2025'], ['D' => '1/02/2025'],
        ['D' => '03/11/2026'], ['D' => '04/11/2026'],
    ], 'D', 2026, new DateTimeImmutable('2026-11-04T01:00:00+05:00'));
    expect($result['years'])->toBe([2024 => 1, 2026 => 2])
        ->and($result['invalid'])->toBe(2)->and($result['future'])->toBe(1)
        ->and($result['selected'])->toBe(1)->and($result['other'])->toBe(1);
});

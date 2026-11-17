<?php

use App\Support\AmountInWords;
use App\Support\Money;

// Quotation PDFs and the client page print the total in words (QUO-014, GLB-012): the Pakistani system.
it('spells quotation totals in crore and lakh', function (int $minor, string $words) {
    expect(AmountInWords::rupees(Money::ofMinor($minor)))->toBe($words);
})->with([
    [0, 'Rupees Zero Only'],
    [100, 'Rupees One Only'],
    [1_500, 'Rupees Fifteen Only'],
    [32_500_000, 'Rupees Three Lakh Twenty Five Thousand Only'],
    [40_000_000, 'Rupees Four Lakh Only'],
    [123_456_789_00, 'Rupees Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine Only'],
    [10_000_000_000_00, 'Rupees One Thousand Crore Only'],
    [99_999_950, 'Rupees Nine Lakh Ninety Nine Thousand Nine Hundred Ninety Nine and Fifty Paisa Only'],
    [-150_000, 'Minus Rupees One Thousand Five Hundred Only'],
]);

<?php

use App\Domain\System\Models\NumberSequence;
use App\Support\Numbering;
use Database\Seeders\NumberSequenceSeeder;

it('allocates sequential Woodex document numbers', function () {
    $numbering = app(Numbering::class);

    expect($numbering->next('quote.interior'))->toBe('WI-10100')
        ->and($numbering->next('quote.interior'))->toBe('WI-10101')
        ->and($numbering->next('quote.furniture'))->toBe('WF-10050')
        ->and($numbering->next('receipt'))->toBe('RC-'.now()->format('Y').'-0001')
        ->and($numbering->peek('quote.interior'))->toBe('WI-10102');
});

it('resets yearly sequences when the year changes', function () {
    NumberSequence::query()->where('key', 'receipt')->update(['next_number' => 57, 'period' => '2025']);

    expect(app(Numbering::class)->next('receipt'))->toBe('RC-'.now()->format('Y').'-0001');
});

it('never resets live counters when the seeder runs again', function () {
    app(Numbering::class)->next('quote.interior');
    $this->seed(NumberSequenceSeeder::class);

    expect(app(Numbering::class)->next('quote.interior'))->toBe('WI-10101');
});

it('fails loudly for an unknown sequence', function () {
    app(Numbering::class)->next('unknown');
})->throws(RuntimeException::class);

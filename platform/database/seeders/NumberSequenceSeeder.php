<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\System\Models\NumberSequence;
use Illuminate\Database\Seeder;

/**
 * Document numbering (D-16). firstOrCreate: re-running NEVER resets a live counter.
 */
class NumberSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $sequences = [
            ['key' => 'furniture.invoice', 'prefix' => 'WFI', 'next_number' => 1, 'padding' => 4, 'format' => '{prefix}-{year}-{number}', 'reset' => 'yearly'],
            ['key' => 'quote.interior', 'prefix' => 'WI', 'next_number' => 10100, 'padding' => 0, 'format' => '{prefix}-{number}', 'reset' => 'never'],
            ['key' => 'quote.furniture', 'prefix' => 'WF', 'next_number' => 10050, 'padding' => 0, 'format' => '{prefix}-{number}', 'reset' => 'never'],
            ['key' => 'receipt', 'prefix' => 'RC', 'next_number' => 1, 'padding' => 4, 'format' => '{prefix}-{year}-{number}', 'reset' => 'yearly'],
            ['key' => 'lead', 'prefix' => 'L', 'next_number' => 1, 'padding' => 6, 'format' => '{prefix}-{year}-{number}', 'reset' => 'yearly'],
            ['key' => 'journal', 'prefix' => 'JE', 'next_number' => 1, 'padding' => 4, 'format' => '{prefix}-{year}-{number}', 'reset' => 'yearly'],
            ['key' => 'estimate', 'prefix' => 'EST', 'next_number' => 1, 'padding' => 4, 'format' => '{prefix}-{year}-{number}', 'reset' => 'yearly'],
        ];

        foreach ($sequences as $sequence) {
            NumberSequence::query()->firstOrCreate(
                ['key' => $sequence['key']],
                $sequence + ['period' => $sequence['reset'] === 'yearly' ? now()->format('Y') : null],
            );
        }
    }
}

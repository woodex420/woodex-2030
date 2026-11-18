<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The number series for recurring invoices (slice E, part 2). A recurring invoice has no project to number
 * itself after, so it gets its own sequence: RI-2026-0001, reset every year, never reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('number_sequences')->where('key', 'recurring.invoice')->exists();

        if (! $exists) {
            DB::table('number_sequences')->insert([
                'key' => 'recurring.invoice',
                'prefix' => 'RI',
                'next_number' => 1,
                'padding' => 4,
                'format' => '{prefix}-{year}-{number}',
                'reset' => 'yearly',
                'period' => now()->format('Y'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('number_sequences')->where('key', 'recurring.invoice')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The number series for manual invoices (Slice P). A manual invoice has no project to number itself after,
 * so it gets its own sequence: MI-2026-0001, reset every year, never reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('number_sequences')->where('key', 'manual.invoice')->exists();

        if (! $exists) {
            DB::table('number_sequences')->insert([
                'key' => 'manual.invoice',
                'prefix' => 'MI',
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
        DB::table('number_sequences')->where('key', 'manual.invoice')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Input tax has to land somewhere. The chart had 2100 Taxes payable (sales tax the studio owes) but no
 * counterpart for tax it has paid on purchases, so a received invoice with tax could not be booked correctly.
 * 1210 is seeded here for existing installs; ChartOfAccountsSeeder carries it for new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parent = DB::table('chart_of_accounts')->where('code', '1000')->value('id');

        // A brand-new database has no chart yet: ChartOfAccountsSeeder carries 1210 for those installs, and this
        // migration only repairs databases that were seeded before the account existed. Never insert a leaf with
        // no parent.
        if ($parent === null || DB::table('chart_of_accounts')->where('code', '1210')->exists()) {
            return;
        }

        DB::table('chart_of_accounts')->insert([
            'code' => '1210',
            'name' => 'Input tax (recoverable)',
            'type' => 'asset',
            'parent_id' => $parent,
            'is_group' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Reference data: removing an account that may already hold postings would break the ledger.
    }
};

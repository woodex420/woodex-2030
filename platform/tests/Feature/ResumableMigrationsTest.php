<?php

use App\Domain\Accounts\Models\PaymentMethodAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| MySQL DDL is not transactional: when an update attempt is interrupted after a CREATE TABLE but
| before the migrations row is written, the tables exist without their records and the retry fatals
| on "table already exists" (exactly what production hit on 17 Oct). These migrations are guarded,
| so the retry records them and moves on. Here we reproduce that stuck state and prove the recovery.
*/

it('resumes a migration whose table exists without its migrations row', function () {
    // The stuck production state: both new tables/column already exist, rows missing.
    expect(Schema::hasTable('payment_method_accounts'))->toBeTrue()
        ->and(Schema::hasTable('estimates'))->toBeTrue()
        ->and(Schema::hasColumn('quotes', 'estimate_id'))->toBeTrue();

    $forgotten = [
        '2026_10_17_160000_create_payment_method_accounts',
        '2026_10_17_170000_create_estimates',
        '2026_10_17_180000_create_automations',
    ];
    DB::table('migrations')->whereIn('migration', $forgotten)->delete();

    // A payment-method mapping recorded before the interruption must survive the recovery.
    PaymentMethodAccount::query()->firstOrCreate(
        ['method' => 'cash'],
        ['account_id' => DB::table('chart_of_accounts')->where('code', '1010')->value('id')],
    );

    $exit = Artisan::call('migrate', ['--force' => true]);

    expect($exit)->toBe(0)
        ->and(DB::table('migrations')->whereIn('migration', $forgotten)->count())->toBe(3)
        ->and(PaymentMethodAccount::query()->where('method', 'cash')->exists())->toBeTrue();

    // And the very next run is a plain no-op.
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
});

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\PaymentMethodAccount;
use Illuminate\Database\Seeder;

/**
 * The starter chart of accounts (docs/18 §2.2): groups organise, leaves receive postings, and no
 * opening amount is ever fabricated — balances exist only once journals are posted. The owner and
 * their accountant review this chart before the 2025 staging (batch H); re-running never duplicates
 * or renames what the studio already adjusted.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['code' => '1000', 'name' => 'Assets', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Liabilities', 'type' => 'liability'],
            ['code' => '3000', 'name' => 'Equity', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Income', 'type' => 'income'],
            ['code' => '5000', 'name' => 'Expenses', 'type' => 'expense'],
        ];

        foreach ($groups as $group) {
            Account::query()->firstOrCreate($group, ['is_group' => true, 'is_active' => true]);
        }

        $parentId = Account::query()->whereIn('code', array_column($groups, 'code'))->pluck('id', 'code');

        $leaves = [
            // Assets
            ['1010', 'Cash in hand', 'asset', '1000', null],
            ['1020', 'Bank — operations', 'asset', '1000', null],
            ['1040', 'Mobile wallets', 'asset', '1000', null],
            ['1030', 'Bank — payroll', 'asset', '1000', null],
            ['1100', 'Accounts receivable', 'asset', '1000', null],
            ['1150', 'Customer advances', 'asset', '1000', null],
            ['1200', 'Supplier advances', 'asset', '1000', null],
            // Slice I: tax paid on a received invoice is an asset until it is claimed, not a payable.
            ['1210', 'Input tax (recoverable)', 'asset', '1000', null],
            ['1300', 'Work in progress', 'asset', '1000', null],
            // Liabilities
            ['2010', 'Accounts payable', 'liability', '2000', null],
            ['2020', 'Retentions payable', 'liability', '2000', null],
            ['2030', 'Accrued expenses', 'liability', '2000', null],
            ['2100', 'Taxes payable', 'liability', '2000', null],
            // Equity
            ['3010', 'Owner capital', 'equity', '3000', null],
            ['3020', 'Owner drawings', 'equity', '3000', null],
            ['3900', 'Retained earnings', 'equity', '3000', null],
            // Income
            ['4010', 'Interior design revenue', 'income', '4000', 'interior'],
            ['4020', 'Furniture revenue', 'income', '4000', 'furniture'],
            ['4090', 'Other income', 'income', '4000', null],
            // Expenses
            ['5010', 'Materials & joinery cost', 'expense', '5000', null],
            ['5020', 'Site labour', 'expense', '5000', null],
            ['5030', 'Subcontractors', 'expense', '5000', null],
            ['5100', 'Salaries & wages', 'expense', '5000', null],
            ['5110', 'Rent & utilities', 'expense', '5000', null],
            ['5120', 'Marketing & advertising', 'expense', '5000', null],
            ['5130', 'Vehicle & fuel', 'expense', '5000', null],
            ['5140', 'Professional fees', 'expense', '5000', null],
            ['5190', 'Other expenses', 'expense', '5000', null],
        ];

        foreach ($leaves as [$code, $name, $type, $parent, $businessLine]) {
            Account::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => $parentId[$parent] ?? null,
                    'business_line' => $businessLine,
                    'is_group' => false,
                    'is_active' => true,
                ],
            );
        }

        $this->seedPaymentMethodAccounts();

        $this->seedCurrentPeriods();
    }

    /**
     * Monthly periods from two months back to three ahead — all open. Closing is a deliberate,
     * recorded act by the owner or accountant; the seeder never closes anything.
     */
    private function seedCurrentPeriods(): void
    {
        $month = now()->startOfMonth()->subMonths(2);

        for ($i = 0; $i < 6; $i++) {
            $start = $month->copy()->addMonths($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            // whereDate, not firstOrCreate: SQLite stores these columns with a
            // time component, so a plain equality probe can miss an existing row
            // and crash on the unique index when the seeder re-runs (DatabaseUpdatesTest).
            $exists = AccountingPeriod::query()
                ->whereDate('starts_on', $start->toDateString())
                ->whereDate('ends_on', $end->toDateString())
                ->exists();

            if (! $exists) {
                AccountingPeriod::query()->create([
                    'name' => $start->format('F Y'),
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $end->toDateString(),
                ]);
            }
        }
    }

    /**
     * Default method → ledger account map for P8.2 receipt posting (owner-adjustable,
     * firstOrCreate so an owner's edit is never overwritten by a re-seed).
     */
    private function seedPaymentMethodAccounts(): void
    {
        $defaults = [
            'cash' => '1010',
            'bank_transfer' => '1020',
            'cheque' => '1020',
            'card' => '1020',
            'gateway' => '1020',
            'jazzcash' => '1040',
            'easypaisa' => '1040',
            'other' => '1010',
        ];

        $ids = Account::query()->whereIn('code', array_values($defaults))->pluck('id', 'code');

        foreach ($defaults as $method => $code) {
            if (! isset($ids[$code])) {
                continue;
            }

            PaymentMethodAccount::query()->firstOrCreate(
                ['method' => $method],
                ['account_id' => $ids[$code]],
            );
        }
    }
}

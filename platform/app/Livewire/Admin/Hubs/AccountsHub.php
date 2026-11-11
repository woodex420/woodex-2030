<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Accounts\ChartOfAccounts;
use App\Livewire\Admin\Accounts\JournalsIndex;
use App\Livewire\Admin\Accounts\MethodAccounts;
use App\Livewire\Admin\Accounts\PeriodsIndex;
use App\Livewire\Admin\Accounts\ProfitLoss;
use App\Livewire\Admin\Accounts\TrialBalance;

/** A front door for existing accounting screens. Original routes and posting rules are unchanged. */
final class AccountsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Ledger & reports',
            'description' => 'Existing accounting tools in one workspace. Payables and Income & expenses remain separate workflows; no records or tables are combined.',
            'breadcrumbs' => ['Accounts' => null, 'Ledger & reports' => null],
            'tabs' => [
                ['id' => 'chart', 'label' => 'Chart of Accounts', 'component' => ChartOfAccounts::class, 'permission' => 'accounts.view'],
                ['id' => 'journals', 'label' => 'Journals', 'component' => JournalsIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'trial', 'label' => 'Trial balance', 'component' => TrialBalance::class, 'permission' => 'accounts.view'],
                ['id' => 'profit', 'label' => 'Profit & loss', 'component' => ProfitLoss::class, 'permission' => 'accounts.view'],
                ['id' => 'periods', 'label' => 'Periods', 'component' => PeriodsIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'methods', 'label' => 'Payment Methods', 'component' => MethodAccounts::class, 'permission' => 'accounts.view'],
            ],
        ];
    }
}

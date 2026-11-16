<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Accounts\ExpensesIndex;
use App\Livewire\Admin\Accounts\OtherIncome;
use App\Livewire\Admin\Accounts\RecurringExpenses;

/**
 * Income and expenses that are not client invoices (slice I) — one-off costs, other income, and the repeating
 * overheads that post themselves.
 */
final class LedgerHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Income & expenses',
            'description' => 'Everything that moves money without a client invoice — one-off costs, other income and the repeating overheads.',
            'breadcrumbs' => ['Accounts' => null, 'Income & expenses' => null],
            'tabs' => [
                ['id' => 'expenses', 'label' => 'Expenses', 'component' => ExpensesIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'income', 'label' => 'Other income', 'component' => OtherIncome::class, 'permission' => 'accounts.view'],
                ['id' => 'recurring', 'label' => 'Recurring', 'component' => RecurringExpenses::class, 'permission' => 'accounts.view'],
            ],
        ];
    }
}

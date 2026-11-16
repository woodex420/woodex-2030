<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Accounts\BillsIndex;
use App\Livewire\Admin\Accounts\ExpensesIndex;
use App\Livewire\Admin\Accounts\VendorsIndex;
use App\Livewire\Admin\Projects\ProjectCosts;

final class ProjectCostsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Vendors & costs',
            'description' => 'Project purchases and the existing supplier/payment records, not a second ledger.',
            'breadcrumbs' => ['Projects' => route('admin.projects.index'), 'Vendors & costs' => null],
            'tabs' => [
                ['id' => 'projects', 'label' => 'Project costs', 'component' => ProjectCosts::class, 'permission' => 'projects.view'],
                ['id' => 'bills', 'label' => 'Supplier bills', 'component' => BillsIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'expenses', 'label' => 'Expenses', 'component' => ExpensesIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'vendors', 'label' => 'Vendors', 'component' => VendorsIndex::class, 'permission' => 'accounts.view'],
            ],
        ];
    }
}

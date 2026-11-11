<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Accounts\BillsIndex;
use App\Livewire\Admin\Accounts\PayablesAging;
use App\Livewire\Admin\Accounts\VendorsIndex;

/**
 * Money out (slice I): the received invoices, the suppliers they come from, and what is still owed by age.
 */
final class PayablesHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Payables',
            'description' => 'What the studio owes: vendor bills, the supplier register and the age of every unpaid rupee.',
            'breadcrumbs' => ['Accounts' => null, 'Payables' => null],
            'tabs' => [
                ['id' => 'bills', 'label' => 'Received invoices', 'component' => BillsIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'vendors', 'label' => 'Vendors', 'component' => VendorsIndex::class, 'permission' => 'accounts.view'],
                ['id' => 'aging', 'label' => 'Aging', 'component' => PayablesAging::class, 'permission' => 'accounts.view'],
            ],
        ];
    }
}

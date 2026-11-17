<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Sales\BankAccounts;
use App\Livewire\Admin\Sales\InvoicesIndex;
use App\Livewire\Admin\Sales\InvoiceTrackingBoard;
use App\Livewire\Admin\Sales\RecurringInvoices;

/**
 * P10.1: the Invoices workflow page — issued invoices and their delivery/payment tracking.
 */
final class InvoicesHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Invoices',
            'description' => 'What has been billed and where each invoice stands — issued to paid.',
            'breadcrumbs' => ['Sales' => null, 'Invoices' => null],
            'tabs' => [
                ['id' => 'all', 'label' => 'All invoices', 'component' => InvoicesIndex::class, 'permission' => 'invoices.view'],
                ['id' => 'tracking', 'label' => 'Tracking', 'component' => InvoiceTrackingBoard::class, 'permission' => 'invoices.view'],
                ['id' => 'recurring', 'label' => 'Recurring', 'component' => RecurringInvoices::class, 'permission' => 'invoices.view'],
                ['id' => 'bank', 'label' => 'Bank accounts', 'component' => BankAccounts::class, 'permission' => 'invoices.view'],
            ],
        ];
    }
}

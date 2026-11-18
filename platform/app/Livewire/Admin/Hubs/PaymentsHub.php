<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Reports\Receivables;
use App\Livewire\Admin\Sales\DuePayments;
use App\Livewire\Admin\Sales\PaymentsIndex;

/**
 * P10.1: the Payments workflow page — money in: receipts, what is due and the receivables book.
 */
final class PaymentsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Payments',
            'description' => 'Money in — receipts recorded, dues to chase and the receivables book behind them.',
            'breadcrumbs' => ['Sales' => null, 'Payments' => null],
            'tabs' => [
                ['id' => 'all', 'label' => 'Payments', 'component' => PaymentsIndex::class, 'permission' => 'invoices.view'],
                ['id' => 'dues', 'label' => 'Due payments', 'component' => DuePayments::class, 'permission' => 'invoices.view'],
                ['id' => 'receivables', 'label' => 'Receivables', 'component' => Receivables::class, 'permission' => 'invoices.view'],
            ],
        ];
    }
}

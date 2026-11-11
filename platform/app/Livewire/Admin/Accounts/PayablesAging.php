<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Who the studio owes, by age (slice I) — the mirror of Accounts receivable. A bill counts until it is paid, and
 * the bucket is the due date, not the bill date: a 30-day term is not overdue on day 1.
 */
#[Layout('layouts::admin')]
#[Title('Payables aging')]
class PayablesAging extends Component
{
    use InteractsWithAdmin;

    public function mount(): void
    {
        $this->authorize('accounts.view');
    }

    public function render(): View
    {
        $bills = Bill::query()
            ->with('vendor')
            ->whereIn('status', [BillStatus::Received, BillStatus::PartiallyPaid])
            ->orderBy('due_date')
            ->get();

        $buckets = [
            'current' => ['label' => 'Not due yet', 'total' => 0, 'bills' => []],
            '1_30' => ['label' => '1–30 days late', 'total' => 0, 'bills' => []],
            '31_60' => ['label' => '31–60 days late', 'total' => 0, 'bills' => []],
            '61_plus' => ['label' => '61+ days late', 'total' => 0, 'bills' => []],
        ];

        foreach ($bills as $bill) {
            $daysLate = $bill->daysOverdue();
            $key = $daysLate === 0 ? 'current' : ($daysLate <= 30 ? '1_30' : ($daysLate <= 60 ? '31_60' : '61_plus'));

            $buckets[$key]['total'] += $bill->balanceMinor();
            $buckets[$key]['bills'][] = $bill;
        }

        return view('livewire.admin.accounts.payables-aging', [
            'buckets' => $buckets,
            'outstanding' => array_sum(array_column($buckets, 'total')),
            'overdue' => $buckets['1_30']['total'] + $buckets['31_60']['total'] + $buckets['61_plus']['total'],
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}

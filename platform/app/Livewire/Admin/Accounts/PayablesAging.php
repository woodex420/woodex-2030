<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Models\Bill;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
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

    /**
     * The dashboard's "payables due", opened (master list T-044): `overdue` or `soon` cuts the bills below to
     * Bill::due() of that name, the very query the figure counted. The two sums above stay those of every bill
     * that is owed on, so that "Total outstanding" never means a part.
     */
    #[Url(except: '')]
    public string $due = '';

    public function mount(): void
    {
        $this->authorize('accounts.view');
    }

    /** "Clear" beside "Filtered: …". */
    public function clearQuickFilters(): void
    {
        $this->reset('due');
    }

    public function render(): View
    {
        abort_unless(in_array($this->due, ['', 'overdue', 'soon'], true), 422, 'Choose which bills to show.');

        $bills = Bill::query()->with('vendor')->owing()->orderBy('due_date')->get();
        // Which of them the filter keeps is asked of the same scope the dashboard's figure asked, not worked out
        // a second time here.
        $kept = $this->due === '' ? null : Bill::query()->due($this->due)->pluck('id')->flip();

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

            if ($kept === null || $kept->has($bill->id)) {
                $buckets[$key]['bills'][] = $bill;
                $buckets[$key]['shown'] = ($buckets[$key]['shown'] ?? 0) + $bill->balanceMinor();
            }
        }

        return view('livewire.admin.accounts.payables-aging', [
            'buckets' => $buckets,
            // Every bill drawn below, over all the ages: the rows a figure that links here has counted.
            'rows' => array_sum(array_map(fn (array $bucket): int => count($bucket['bills']), $buckets)),
            'filtered' => match ($this->due) {
                'overdue' => ['Past the due date'],
                'soon' => ['Due today or within '.Bill::DUE_SOON_DAYS.' days'],
                default => [],
            },
            'outstanding' => array_sum(array_column($buckets, 'total')),
            'overdue' => $buckets['1_30']['total'] + $buckets['31_60']['total'] + $buckets['61_plus']['total'],
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}

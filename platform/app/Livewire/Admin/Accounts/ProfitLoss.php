<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalLine;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Profit & loss over the same posted lines the trial balance reads (slice I). Income accounts (4xxx) minus expense
 * accounts (5xxx) for the range, with the cost of sales split from overheads so the studio can see gross margin as
 * well as the net. No figure is typed: every line here is a sum of journal lines, and the screen says so.
 */
#[Layout('layouts::admin')]
#[Title('Profit & loss')]
class ProfitLoss extends Component
{
    use InteractsWithAdmin;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->authorize('accounts.view');
        $this->from = now()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    public function preset(string $range): void
    {
        $this->authorize('accounts.view');

        [$this->from, $this->to] = match ($range) {
            'month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'quarter' => [today()->startOfQuarter()->toDateString(), today()->toDateString()],
            'year' => [today()->startOfYear()->toDateString(), today()->toDateString()],
            default => ['2000-01-01', today()->toDateString()],
        };
    }

    public function render(): View
    {
        $from = $this->from !== '' ? $this->from : now()->startOfYear()->toDateString();
        $to = $this->to !== '' ? $this->to : today()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('chart_of_accounts.type', ['income', 'expense'])
            ->whereDate('journal_entries.business_date', '>=', $from)
            ->whereDate('journal_entries.business_date', '<=', $to)
            ->groupBy('journal_lines.account_id', 'chart_of_accounts.type')
            ->selectRaw('journal_lines.account_id as account_id, chart_of_accounts.type as type, sum(journal_lines.debit_minor) as debit_minor, sum(journal_lines.credit_minor) as credit_minor')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()
            ->whereIn('type', ['income', 'expense'])
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        // Cost of sales is the direct cost tree (5010–5099); the rest of the 5xxx range is overhead.
        $costOfSales = [];
        $overheads = [];
        $income = [];
        $totalIncome = 0;
        $totalCostOfSales = 0;
        $totalOverheads = 0;

        foreach ($accounts as $account) {
            $sum = $sums->get($account->id);

            if ($sum === null) {
                continue;
            }

            $debit = (int) $sum->debit_minor;
            $credit = (int) $sum->credit_minor;

            if ($account->type->value === 'income') {
                $net = $credit - $debit;

                if ($net === 0) {
                    continue;
                }

                $income[] = ['account' => $account, 'amount' => $net];
                $totalIncome += $net;

                continue;
            }

            $net = $debit - $credit;

            if ($net === 0) {
                continue;
            }

            $row = ['account' => $account, 'amount' => $net];

            if ((int) $account->code >= 5010 && (int) $account->code <= 5099) {
                $costOfSales[] = $row;
                $totalCostOfSales += $net;
            } else {
                $overheads[] = $row;
                $totalOverheads += $net;
            }
        }

        $grossProfit = $totalIncome - $totalCostOfSales;

        return view('livewire.admin.accounts.profit-loss', [
            'income' => $income,
            'costOfSales' => $costOfSales,
            'overheads' => $overheads,
            'totalIncome' => $totalIncome,
            'totalCostOfSales' => $totalCostOfSales,
            'totalOverheads' => $totalOverheads,
            'grossProfit' => $grossProfit,
            'netProfit' => $grossProfit - $totalOverheads,
            'from' => $from,
            'to' => $to,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}

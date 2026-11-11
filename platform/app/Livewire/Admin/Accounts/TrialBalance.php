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
 * Trial balance (deep QA 26 Oct, Slice H part 2). The chart shows what the accounts are; the journals show what was
 * posted; nothing answered the one question an accountant asks first — **do the debits equal the credits?**
 *
 * Every figure here is a sum over posted journal lines in the chosen range, so a reversal cancels its original and
 * a voided invoice nets to zero on its own. No opening balances are invented: if the range starts after the first
 * posting, that is stated by the range itself.
 */
#[Layout('layouts::admin')]
#[Title('Trial balance')]
class TrialBalance extends Component
{
    use InteractsWithAdmin;

    public string $from = '';

    public string $to = '';

    public bool $showEmpty = false;

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
            ->whereDate('journal_entries.business_date', '>=', $from)
            ->whereDate('journal_entries.business_date', '<=', $to)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as account_id, sum(journal_lines.debit_minor) as debit_minor, sum(journal_lines.credit_minor) as credit_minor, count(distinct journal_entries.id) as entries')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_active']);

        $rows = $accounts->map(function (Account $account) use ($sums): array {
            $sum = $sums->get($account->id);
            $debit = (int) ($sum->debit_minor ?? 0);
            $credit = (int) ($sum->credit_minor ?? 0);

            return [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'net' => $debit - $credit,
                'entries' => (int) ($sum->entries ?? 0),
            ];
        })->filter(fn (array $row): bool => $this->showEmpty || $row['debit'] !== 0 || $row['credit'] !== 0)->values();

        $totalDebit = (int) $rows->sum('debit');
        $totalCredit = (int) $rows->sum('credit');
        $entryCount = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereDate('journal_entries.business_date', '>=', $from)
            ->whereDate('journal_entries.business_date', '<=', $to)
            ->distinct()
            ->count('journal_entries.id');

        return view('livewire.admin.accounts.trial-balance', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'difference' => $totalDebit - $totalCredit,
            'entryCount' => $entryCount,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Actions\PostJournalEntry;
use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalEntry;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journals (P8.1 batch C): every posted entry is immutable — corrections are linked reversals.
 * The owner can also post a manual entry (a correction or a cash expense without a source document).
 */
#[Layout('layouts::admin')]
#[Title('Journals')]
class JournalsIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    public bool $showForm = false;

    public string $businessDate = '';

    public string $reference = '';

    public string $note = '';

    /** @var list<array{account_id: ?int, debit: string, credit: string, memo: string}> */
    public array $lines = [];

    #[Locked]
    public ?int $reversingId = null;

    public string $reversalReason = '';

    public function mount(): void
    {
        $this->businessDate = today()->toDateString();
        $this->lines = [$this->blankLine(), $this->blankLine()];
    }

    public function render(): View
    {
        return view('livewire.admin.accounts.journals-index', [
            'entries' => $this->entries(),
            'accounts' => Account::query()->where('is_group', false)->where('is_active', true)->orderBy('code')->get(),
            'canManage' => $this->user()->can('accounts.manage'),
        ]);
    }

    public function startEntry(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);
        $this->reset(['reference', 'note']);
        $this->businessDate = today()->toDateString();
        $this->lines = [$this->blankLine(), $this->blankLine()];
        $this->showForm = true;
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->lines = [$this->blankLine()];
        }
    }

    public function post(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $this->validate([
            'businessDate' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $entry = app(PostJournalEntry::class)->handle($this->user(), array_map(
                fn (array $line): array => [
                    'account_id' => (int) $line['account_id'],
                    'debit_minor' => isset($line['debit']) && $line['debit'] !== '' ? (int) round(((float) $line['debit']) * 100) : 0,
                    'credit_minor' => isset($line['credit']) && $line['credit'] !== '' ? (int) round(((float) $line['credit']) * 100) : 0,
                    'memo' => $line['memo'] !== '' ? $line['memo'] : null,
                ],
                $this->lines,
            ), [
                'business_date' => $this->businessDate,
                'reference' => $this->reference !== '' ? $this->reference : null,
                'note' => $this->note !== '' ? $this->note : null,
            ]);

            $this->showForm = false;
            $this->toast("Posted {$entry->entry_no}.");
        } catch (JournalPostingFailed $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function askReverse(int $id): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);
        $this->reversingId = $id;
        $this->reversalReason = '';
    }

    public function reverse(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $this->validate(['reversalReason' => ['nullable', 'string', 'max:500']]);

        try {
            $reversal = app(ReverseJournalEntry::class)->handle(
                JournalEntry::query()->findOrFail($this->reversingId),
                $this->user(),
                $this->reversalReason !== '' ? $this->reversalReason : null,
            );

            $this->reversingId = null;
            $this->toast("Reversed — posted {$reversal->entry_no}.");
        } catch (JournalPostingFailed $exception) {
            $this->reversingId = null;
            $this->toast($exception->getMessage(), 'error');
        }
    }

    /** @return LengthAwarePaginator<int, JournalEntry> */
    private function entries(): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with(['lines.account', 'creator'])
            ->orderByDesc('id')
            ->paginate(20);
    }

    /** @return array{account_id: null, debit: string, credit: string, memo: string} */
    private function blankLine(): array
    {
        return ['account_id' => null, 'debit' => '', 'credit' => '', 'memo' => ''];
    }
}

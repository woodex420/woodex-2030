<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Models\BankAccount;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where clients send money (INV-004). The dashboard tells the owner to add bank accounts "under Invoices → Bank
 * accounts", the invoice snapshot prints the chosen account, and the client's invoice page shows it — but until
 * now there was no screen to type one in, so the table was empty on every install and the invoice printed no
 * payment details at all.
 *
 * Read by anyone who may see invoices; edited only with `invoices.manage`. Nothing is deleted: an account that is
 * no longer used is deactivated, because issued invoices point at it.
 */
#[Layout('layouts::admin')]
#[Title('Bank accounts')]
class BankAccounts extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $bankName = '';

    public string $accountNumber = '';

    public string $iban = '';

    public string $branch = '';

    public string $branchCode = '';

    public string $walletProvider = '';

    public string $kind = 'bank';

    public string $businessLine = '';

    public bool $isDefault = false;

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('invoices.view');
    }

    public function openForm(?int $id = null): void
    {
        $this->authorize('invoices.manage');
        $this->resetValidation();

        $this->editingId = $id;
        $account = $id !== null ? BankAccount::query()->findOrFail($id) : null;

        $this->title = $account->title ?? '';
        $this->bankName = $account->bank_name ?? '';
        $this->accountNumber = $account->account_number ?? '';
        $this->iban = $account->iban ?? '';
        $this->branch = $account->branch ?? '';
        $this->branchCode = $account->branch_code ?? '';
        $this->walletProvider = $account->wallet_provider ?? '';
        $this->kind = $account->kind ?? 'bank';
        $this->businessLine = $account === null ? '' : (string) ($account->getRawOriginal('business_line') ?? '');
        $this->isDefault = (bool) ($account->is_default ?? false);
        $this->isActive = (bool) ($account->is_active ?? true);
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('invoices.manage');

        $data = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'bankName' => ['required', 'string', 'max:120'],
            'accountNumber' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:160'],
            'branchCode' => ['nullable', 'string', 'max:20'],
            'walletProvider' => ['nullable', 'string', 'max:20'],
            'kind' => ['required', 'in:bank,wallet'],
            'businessLine' => ['nullable', 'in:interior,furniture'],
            'isDefault' => ['boolean'],
            'isActive' => ['boolean'],
        ]);

        $attributes = [
            'title' => $data['title'],
            'bank_name' => $data['bankName'],
            'account_number' => $this->blankToNull($data['accountNumber']),
            'iban' => $this->blankToNull($data['iban']),
            'branch' => $this->blankToNull($data['branch']),
            'branch_code' => $this->blankToNull($data['branchCode']),
            'wallet_provider' => $this->blankToNull($data['walletProvider']),
            'kind' => $data['kind'],
            'business_line' => $data['businessLine'] !== '' ? $data['businessLine'] : null,
            'is_default' => (bool) $data['isDefault'],
            'is_active' => (bool) $data['isActive'],
        ];

        DB::transaction(function () use ($attributes): void {
            if ($attributes['is_default']) {
                $this->clearDefaults($attributes['business_line']);
            }

            $account = $this->editingId !== null
                ? BankAccount::query()->findOrFail($this->editingId)
                : new BankAccount;

            $account->fill($attributes)->save();

            activity('billing')
                ->causedBy($this->user())
                ->performedOn($account)
                ->withProperties(['business_line' => $attributes['business_line'], 'is_default' => $attributes['is_default']])
                ->log(($this->editingId !== null ? 'Updated' : 'Added').' bank account '.$account->title.'.');
        });

        $this->showForm = false;
        $this->editingId = null;

        session()->flash('status', 'Saved. New invoices for this line will print these details.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('invoices.manage');

        $account = BankAccount::query()->findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);

        session()->flash('status', $account->title.($account->is_active ? ' is active again.' : ' is deactivated — existing invoices keep it.'));
    }

    public function makeDefault(int $id): void
    {
        $this->authorize('invoices.manage');

        $account = BankAccount::query()->findOrFail($id);

        DB::transaction(function () use ($account): void {
            $this->clearDefaults($account->business_line?->value);
            $account->update(['is_default' => true, 'is_active' => true]);
        });

        session()->flash('status', $account->title.' is now the default for '.($account->business_line?->label() ?? 'both lines').'.');
    }

    public function render(): View
    {
        return view('livewire.admin.sales.bank-accounts', [
            'accounts' => BankAccount::query()->orderByRaw('business_line is null')->orderBy('business_line')->orderBy('position')->orderBy('id')->get(),
            'canManage' => $this->user()->can('invoices.manage'),
        ]);
    }

    private function clearDefaults(?string $businessLine): void
    {
        BankAccount::query()
            ->where(fn ($query) => $businessLine === null
                ? $query->whereNull('business_line')
                : $query->where('business_line', $businessLine))
            ->update(['is_default' => false]);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' || $value === null ? null : $value;
    }
}

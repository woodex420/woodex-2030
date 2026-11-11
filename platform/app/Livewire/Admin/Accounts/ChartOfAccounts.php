<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The chart of accounts (P8.1 batch C): groups organise, leaves receive postings. The owner reviews
 * this chart with their accountant before the 2025 staging; nothing here carries opening balances.
 */
#[Layout('layouts::admin')]
#[Title('Chart of accounts')]
class ChartOfAccounts extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $type = 'asset';

    public ?int $parentId = null;

    public string $businessLine = '';

    public bool $isActive = true;

    public function render(): View
    {
        $accounts = Account::query()->with('parent')->orderBy('code')->get();
        $groups = Account::query()->where('is_group', true)->orderBy('code')->get();

        return view('livewire.admin.accounts.chart-of-accounts', [
            'byType' => collect(AccountType::balanced())->mapWithKeys(
                fn (AccountType $type): array => [$type->label() => $accounts->where('type', $type)],
            ),
            'groups' => $groups,
            'canManage' => auth()->user()?->can('accounts.manage'),
        ]);
    }

    public function create(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $account = Account::query()->findOrFail($id);
        $this->editingId = $account->id;
        $this->code = $account->code;
        $this->name = $account->name;
        $this->type = $account->type->value;
        $this->parentId = $account->parent_id;
        $this->businessLine = $account->business_line === null ? '' : $account->business_line->value;
        $this->isActive = $account->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $unique = $this->editingId === null ? 'unique:chart_of_accounts,code' : 'unique:chart_of_accounts,code,'.$this->editingId;

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[0-9A-Za-z\-\.]+$/', $unique],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', new Enum(AccountType::class)],
            'parentId' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'businessLine' => ['nullable', 'in:interior,furniture'],
            'isActive' => ['boolean'],
        ]);

        // Groups and leaf accounts share this form: a new account is a group only when it has no
        // parent, matching the seeded chart. An existing group keeps its nature — journals may point at it.
        $isGroup = $this->editingId !== null
            ? (bool) Account::query()->findOrFail($this->editingId)->is_group
            : $validated['parentId'] === null;

        Account::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'code' => $validated['code'],
                'name' => $validated['name'],
                'type' => $validated['type'],
                'parent_id' => $validated['parentId'],
                'business_line' => $validated['businessLine'] !== '' ? $validated['businessLine'] : null,
                'is_group' => $isGroup,
                'is_active' => $validated['isActive'],
            ],
        );

        $this->resetForm();
        $this->showForm = false;
        $this->toast('Account saved.');
    }

    public function toggleActive(int $id): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $account = Account::query()->findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->code = '';
        $this->name = '';
        $this->type = 'asset';
        $this->parentId = null;
        $this->businessLine = '';
        $this->isActive = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }
}

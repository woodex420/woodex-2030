<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\PaymentMethodAccount;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * P8.2: where each payment method's money lands in the ledger. Owner-only — an unmapped method
 * is a hard stop on receipt posting, so this screen is the fix for that message, not a nice-to-have.
 */
#[Layout('layouts::admin')]
#[Title('Payment methods')]
class MethodAccounts extends Component
{
    use InteractsWithAdmin;

    /** @var array<string, int|string> method => account id */
    public array $map = [];

    public function mount(): void
    {
        $this->map = PaymentMethodAccount::query()->pluck('account_id', 'method')->map(
            static fn ($accountId): int => (int) $accountId,
        )->all();
    }

    public function render(): View
    {
        $accounts = Account::query()
            ->where('is_group', false)
            ->where('is_active', true)
            ->where('type', 'asset')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('livewire.admin.accounts.method-accounts', [
            'methods' => PaymentMethod::cases(),
            'accounts' => $accounts,
            'canManage' => $this->user()->can('accounts.manage'),
        ]);
    }

    public function save(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $rules = [];

        foreach (PaymentMethod::cases() as $method) {
            $rules['map.'.$method->value] = ['required', 'integer', Rule::exists(Account::class, 'id')->where('is_active', true)];
        }

        $validated = $this->validate($rules);

        foreach (PaymentMethod::cases() as $method) {
            PaymentMethodAccount::query()->updateOrCreate(
                ['method' => $method->value],
                ['account_id' => (int) $validated['map'][$method->value]],
            );
        }

        $this->toast('Payment method accounts saved — receipts now post to these accounts.', 'success');
    }
}

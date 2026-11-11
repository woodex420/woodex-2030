<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Vendor;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The supplier register (slice I). Every bill, every project cost and every overhead names one of these rows, so
 * the name, phone and payment terms are typed once. A vendor that is no longer used is deactivated — old bills
 * still point at it.
 */
#[Layout('layouts::admin')]
#[Title('Vendors')]
class VendorsIndex extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $city = '';

    public string $address = '';

    public string $taxNumber = '';

    public string $termsDays = '0';

    public ?int $expenseAccountId = null;

    public bool $isActive = true;

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('accounts.view');
    }

    public function openForm(?int $id = null): void
    {
        $this->authorize('accounts.manage');
        $this->resetValidation();

        $vendor = $id !== null ? Vendor::query()->findOrFail($id) : null;
        $this->editingId = $vendor?->id;
        $this->name = $vendor->name ?? '';
        $this->phone = $vendor->phone ?? '';
        $this->email = $vendor->email ?? '';
        $this->city = $vendor->city ?? '';
        $this->address = $vendor->address ?? '';
        $this->taxNumber = $vendor->tax_number ?? '';
        $this->termsDays = (string) ($vendor->payment_terms_days ?? 0);
        $this->expenseAccountId = $vendor?->expense_account_id;
        $this->isActive = $vendor->is_active ?? true;
        $this->notes = $vendor->notes ?? '';
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
        $this->authorize('accounts.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'taxNumber' => ['nullable', 'string', 'max:40'],
            'termsDays' => ['required', 'integer', 'min:0', 'max:365'],
            'expenseAccountId' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'isActive' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $attributes = [
            'name' => $data['name'],
            'phone' => $this->blankToNull($data['phone']),
            'email' => $this->blankToNull($data['email']),
            'city' => $this->blankToNull($data['city']),
            'address' => $this->blankToNull($data['address']),
            'tax_number' => $this->blankToNull($data['taxNumber']),
            'payment_terms_days' => (int) $data['termsDays'],
            'expense_account_id' => $data['expenseAccountId'] ?: null,
            'is_active' => (bool) $data['isActive'],
            'notes' => $this->blankToNull($data['notes']),
        ];

        $vendor = $this->editingId !== null
            ? Vendor::query()->findOrFail($this->editingId)
            : new Vendor;

        $vendor->fill($attributes)->save();

        activity('accounts')
            ->causedBy($this->user())
            ->performedOn($vendor)
            ->log(($this->editingId !== null ? 'Updated' : 'Added').' vendor '.$vendor->name.'.');

        $this->showForm = false;
        $this->editingId = null;
        $this->toast('Vendor saved — it can be picked on bills and expenses.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('accounts.manage');

        $vendor = Vendor::query()->findOrFail($id);
        $vendor->update(['is_active' => ! $vendor->is_active]);

        $this->toast($vendor->name.($vendor->is_active ? ' is active again.' : ' is deactivated — old bills keep it.'));
    }

    public function render(): View
    {
        return view('livewire.admin.accounts.vendors-index', [
            'vendors' => Vendor::query()
                ->withCount(['bills', 'expenses'])
                ->orderByRaw('is_active desc')
                ->orderBy('name')
                ->get(),
            'accounts' => Account::query()
                ->whereIn('type', ['expense', 'asset'])
                ->where('is_group', false)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'canManage' => $this->user()->can('accounts.manage'),
        ]);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' || $value === null ? null : $value;
    }
}

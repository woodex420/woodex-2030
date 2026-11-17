<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Models\AccountingPeriod;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Accounting periods (P8.1 batch C): posting needs an open period that covers the business date.
 * Closing and reopening are deliberate, recorded acts — the closer and timestamp stay on the row.
 */
#[Layout('layouts::admin')]
#[Title('Accounting periods')]
class PeriodsIndex extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    public string $name = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public function render(): View
    {
        return view('livewire.admin.accounts.periods-index', [
            'periods' => AccountingPeriod::query()->with('closer')->orderByDesc('starts_on')->limit(60)->get(),
            'canManage' => $this->user()->can('accounts.manage'),
        ]);
    }

    public function create(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);
        $this->reset(['name', 'startsOn', 'endsOn']);
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
        ]);

        AccountingPeriod::query()->create([
            'name' => $validated['name'],
            'starts_on' => $validated['startsOn'],
            'ends_on' => $validated['endsOn'],
        ]);

        $this->showForm = false;
        $this->toast('Period created.');
    }

    public function close(int $id): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $period = AccountingPeriod::query()->findOrFail($id);

        if ($period->isOpen()) {
            $period->update(['closed_at' => now(), 'closed_by' => $this->user()->id]);
            $this->toast("Period {$period->name} closed. Backdated posting into it is blocked.");
        }
    }

    public function reopen(int $id): void
    {
        abort_unless($this->user()->can('accounts.manage'), 403);

        $period = AccountingPeriod::query()->findOrFail($id);

        if (! $period->isOpen()) {
            $period->update(['closed_at' => null, 'closed_by' => null]);
            $this->toast("Period {$period->name} reopened. The change is recorded in the audit log.");
        }
    }
}

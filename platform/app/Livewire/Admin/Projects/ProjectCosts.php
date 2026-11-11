<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Projects;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Projects\Actions\RecordProjectCost;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\ProjectCostSummary;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Reused in the Vendors & costs hub and inside a project; the financial action is shared. */
final class ProjectCosts extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Locked]
    public ?int $projectId = null;

    #[Url(as: 'costProject')]
    public ?int $selectedProjectId = null;

    public bool $showForm = false;

    public string $kind = 'bill';

    public ?int $vendorId = null;

    public ?int $accountId = null;

    public string $description = '';

    public string $reference = '';

    public string $amountRupees = '';

    public string $spentOn = '';

    public string $dueDate = '';

    public string $paidFrom = 'bank';

    #[Locked]
    public string $requestKey = '';

    public function mount(?int $projectId = null): void
    {
        $this->authorize('accounts.view');
        $this->authorize('projects.view');
        $this->projectId = $projectId;
        $this->currentProject();
        $this->spentOn = today()->toDateString();
        $this->requestKey = (string) Str::uuid();
    }

    public function updatedSelectedProjectId(): void
    {
        $this->currentProject();
        $this->showForm = false;
        $this->resetValidation();
        $this->resetPage('costBillsPage');
        $this->resetPage('costExpensesPage');
        // Do not rotate request identity here: a delayed retry must not become a second purchase.
    }

    public function openForm(): void
    {
        $this->authorize('accounts.manage');
        if ($this->currentProject() === null) {
            $this->addError('selectedProjectId', 'Choose a project first.');

            return;
        }
        $this->showForm = true;
    }

    /** Explicit new-purchase intent, not a source-kind toggle or automatic retry. */
    public function newCost(): void
    {
        $this->authorize('accounts.manage');
        $this->reset('vendorId', 'accountId', 'description', 'reference', 'amountRupees', 'dueDate');
        $this->requestKey = (string) Str::uuid();
        $this->resetValidation();
        $this->openForm();
    }

    public function save(): void
    {
        $this->authorize('accounts.manage');
        $project = $this->currentProject();
        if ($project === null) {
            $this->addError('selectedProjectId', 'Choose a project first.');

            return;
        }
        $this->validate(['amountRupees' => ['required', 'regex:/^\d+(\.\d+)?$/']]);
        try {
            $amount = Money::ofRupees($this->amountRupees)->minor;
        } catch (InvalidArgumentException|MathException $exception) {
            $this->addError('amountRupees', 'Enter an amount within the supported money range.');

            return;
        }
        try {
            $source = app(RecordProjectCost::class)->handle($project, [
                'kind' => $this->kind, 'vendor_id' => $this->vendorId ?? 0, 'account_id' => $this->accountId ?? 0,
                'description' => $this->description, 'reference' => $this->reference,
                'amount_minor' => $amount, 'spent_on' => $this->spentOn,
                'due_date' => $this->kind === 'bill' && $this->dueDate !== '' ? $this->dueDate : null,
                'paid_from' => $this->kind === 'paid' ? $this->paidFrom : null,
            ], $this->user(), $this->requestKey);
        } catch (ValidationException $exception) {
            $fields = ['vendor_id' => 'vendorId', 'account_id' => 'accountId', 'amount_minor' => 'amountRupees', 'spent_on' => 'spentOn', 'due_date' => 'dueDate', 'paid_from' => 'paidFrom', 'project_id' => 'selectedProjectId', 'request_key' => 'reference'];
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($fields[$field] ?? $field, (string) $messages[0]);
            }

            return;
        } catch (JournalPostingFailed $exception) {
            $this->addError('reference', $exception->getMessage());

            return;
        }
        $this->showForm = false;
        $this->resetPage('costBillsPage');
        $this->resetPage('costExpensesPage');
        // Keep fields/key until the user explicitly starts another cost: late retries are safe.
        $this->toast($source instanceof Bill ? 'Supplier bill recorded. Pay this bill rather than entering the cost again.' : 'Paid cost recorded and posted once.');
    }

    private function currentProject(): ?Project
    {
        $this->authorize('accounts.view');
        $this->authorize('projects.view');
        $id = $this->projectId ?? $this->selectedProjectId;
        if ($id === null) {
            return null;
        }
        $project = Project::query()->findOrFail($id);
        $this->authorize('view', $project);

        return $project;
    }

    public function render(): View
    {
        $project = $this->currentProject();

        return view('livewire.admin.projects.project-costs', [
            'project' => $project,
            'projects' => $this->projectId === null ? Project::query()->orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'summary' => $project === null ? null : app(ProjectCostSummary::class)->forProject($project),
            'bills' => $project === null ? null : Bill::query()->where('project_id', $project->id)->with(['vendor', 'journalEntry'])->orderByDesc('id')->paginate(10, pageName: 'costBillsPage'),
            'paidCosts' => $project === null ? null : Expense::query()->where('project_id', $project->id)->where('direction', 'out')->with(['vendor', 'journalEntry'])->orderByDesc('id')->paginate(10, pageName: 'costExpensesPage'),
            'vendors' => $this->showForm ? Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'accounts' => $this->showForm ? Account::query()->where('is_active', true)->where('is_group', false)->where('type', 'expense')->orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'canViewInvoices' => $this->user()->can('invoices.view'),
            'canManage' => $this->user()->is_active && $this->user()->can('accounts.manage'),
        ]);
    }
}

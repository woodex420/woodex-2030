<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Accounts\Actions\RecordExpense;
use App\Domain\Accounts\Actions\SaveBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\ProjectCostAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A purchase has ONE financial source: an unpaid bill OR a paid expense, never both. */
final class RecordProjectCost
{
    /**
     * @param  array{kind: string, vendor_id: int, account_id: int, amount_minor: int, description: string, reference: string, spent_on: string, due_date?: ?string, paid_from?: ?string}  $attributes
     */
    public function handle(Project $project, array $attributes, User $actor, string $requestKey): Bill|Expense
    {
        $actor = app(ProjectCostAccess::class)->actor($actor);
        $project = app(ProjectCostAccess::class)->project($project->id, $actor);
        if ($project === null) {
            throw ValidationException::withMessages(['project_id' => 'Choose an existing project.']);
        }
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();
        $requestKey = strtolower($requestKey);
        $data = Validator::make($attributes, [
            'kind' => ['required', 'in:bill,paid'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'amount_minor' => ['required', 'integer', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:60'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:spent_on'],
            'paid_from' => ['required_if:kind,paid', 'nullable', 'in:cash,bank,wallet'],
        ])->validate();
        $data = [
            'project_id' => $project->id, 'kind' => $data['kind'],
            'vendor_id' => (int) $data['vendor_id'], 'account_id' => (int) $data['account_id'],
            'amount_minor' => (int) $data['amount_minor'], 'description' => trim($data['description']),
            'reference' => trim($data['reference']), 'spent_on' => $data['spent_on'],
            'due_date' => $data['kind'] === 'bill' ? ($data['due_date'] ?? null) : null,
            'paid_from' => $data['kind'] === 'paid' ? $data['paid_from'] : null,
        ];
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($project, $data, $actor, $requestKey, $fingerprint): Bill|Expense {
            // Reserve one global request identity, including across different project IDs/source kinds.
            DB::table('project_cost_requests')->insertOrIgnore([
                'request_key' => $requestKey, 'project_id' => $project->id,
                'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $request = DB::table('project_cost_requests')->where('request_key', $requestKey)->lockForUpdate()->firstOrFail();
            if (! hash_equals($request->fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['reference' => 'This request already recorded a different cost. Refresh before recording another purchase.']);
            }
            if ($request->bill_id !== null) {
                return Bill::query()->findOrFail($request->bill_id);
            }
            if ($request->expense_id !== null) {
                return Expense::query()->findOrFail($request->expense_id);
            }

            if ($data['kind'] === 'bill') {
                $source = app(SaveBill::class)->handle([
                    'project_id' => $project->id, 'vendor_id' => $data['vendor_id'],
                    'number' => $data['reference'], 'bill_date' => $data['spent_on'], 'due_date' => $data['due_date'],
                ], [['account_id' => $data['account_id'], 'description' => $data['description'], 'amount_minor' => $data['amount_minor']]], $actor, BillStatus::Received);
                $column = 'bill_id';
            } else {
                $source = app(RecordExpense::class)->handle([
                    'project_id' => $project->id, 'vendor_id' => $data['vendor_id'],
                    'direction' => 'out', 'account_id' => $data['account_id'], 'amount_minor' => $data['amount_minor'],
                    'description' => $data['description'], 'reference' => $data['reference'],
                    'spent_on' => $data['spent_on'], 'paid_from' => $data['paid_from'],
                ], $actor);
                $column = 'expense_id';
            }
            DB::table('project_cost_requests')->where('request_key', $requestKey)->update([$column => $source->id, 'updated_at' => now()]);

            return $source;
        });
    }
}

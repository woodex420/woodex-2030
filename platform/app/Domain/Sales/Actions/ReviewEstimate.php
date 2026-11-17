<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Services\EstimateAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The deliberate review act (P8.3): a second pair of eyes records that the estimate was read,
 * not just saved. Reviewed estimates can still be edited back to draft reality by re-saving.
 */
final class ReviewEstimate
{
    public function handle(Estimate $estimate, User $by): Estimate
    {
        $by = User::query()->findOrFail($by->id);
        if (! $by->is_active || ! $by->can('estimates.manage')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($estimate, $by): Estimate {
            $estimate = Estimate::query()->lockForUpdate()->findOrFail($estimate->id);
            EstimateAccess::authorize($estimate, $by);

            if ($estimate->status === EstimateStatus::Converted) {
                throw ValidationException::withMessages(['estimate' => 'This estimate is already converted — reviewing history changes nothing.']);
            }

            $estimate->forceFill([
                'status' => EstimateStatus::Reviewed,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ])->save();

            return $estimate->refresh();
        });
    }
}

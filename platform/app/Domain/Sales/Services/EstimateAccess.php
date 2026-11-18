<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Policies\ContactPolicy;
use App\Domain\Sales\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** The hub and every estimate action share the same record-level visibility. */
final class EstimateAccess
{
    /** @return Builder<Estimate> */
    public static function query(User $user): Builder
    {
        return Estimate::query()->when(! $user->can('leads.view_all'), fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->whereHas('lead', fn (Builder $q) => $q->where('owner_id', $user->id))
            ->orWhere(fn (Builder $q) => $q->whereNull('lead_id')->where('created_by', $user->id))));
    }

    public static function authorize(Estimate $estimate, User $user): void
    {
        abort_unless($user->is_active && self::query($user)->whereKey($estimate->id)->exists(), 403);
        if ($estimate->lead_id !== null) {
            self::lead(Lead::query()->findOrFail($estimate->lead_id), $user);
        }
    }

    public static function lead(Lead $lead, User $user): void
    {
        Gate::forUser($user)->authorize('view', $lead);
    }

    /** @return Builder<Contact> */
    public static function contacts(User $user): Builder
    {
        return Contact::query()->when(! ContactPolicy::seesAll($user), fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('owner_id', $user->id)->orWhereHas('leads', fn (Builder $q) => $q->where('owner_id', $user->id))));
    }
}

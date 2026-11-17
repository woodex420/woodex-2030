<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Lead;
use App\Models\User;

/**
 * Lead access per the permission matrix (PRD §7). Owners pass every check via Gate::before.
 */
final class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can('leads.view_all') || ($user->can('leads.view') && $lead->owner_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can('leads.update') && ($user->can('leads.view_all') || $lead->owner_id === $user->id);
    }

    public function assign(User $user): bool
    {
        return $user->can('leads.assign');
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('leads.delete');
    }
}

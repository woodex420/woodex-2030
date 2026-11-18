<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Company;
use App\Models\User;

/**
 * Companies are shared reference records (CRM-002): everyone with CRM access sees them; the leads and
 * contacts listed on a company page are still filtered by each person's own visibility.
 */
final class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('contacts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contacts.update');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('contacts.update');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can('leads.delete');
    }
}

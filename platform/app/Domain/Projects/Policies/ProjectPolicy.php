<?php

declare(strict_types=1);

namespace App\Domain\Projects\Policies;

use App\Domain\Projects\Models\Project;
use App\Models\User;

/**
 * Who may see and change a project (docs/02 §7): everybody who may look at projects sees them; only the people
 * who run the work (projects.manage) may move a stage, post to the site diary or raise an invoice.
 */
final class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.manage');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.manage');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Projects\Services;

use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ProjectCostAccess
{
    public function actor(User $actor): User
    {
        $actor = User::query()->findOrFail($actor->id);
        if (! $actor->is_active || ! $actor->can('accounts.manage')) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    public function project(?int $projectId, User $actor, bool $allowDeleted = false): ?Project
    {
        if ($projectId === null) {
            return null;
        }
        $project = Project::query()->when($allowDeleted, fn ($query) => $query->withTrashed())->find($projectId);
        if ($project === null) {
            throw ValidationException::withMessages(['project_id' => 'Choose an existing, non-deleted project.']);
        }
        if (! $actor->can('view', $project)) {
            throw new AuthorizationException;
        }

        return $project;
    }
}

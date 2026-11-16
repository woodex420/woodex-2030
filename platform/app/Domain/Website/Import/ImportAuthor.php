<?php

declare(strict_types=1);

namespace App\Domain\Website\Import;

use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The user the import writes on behalf of.
 *
 * An imported page needs an author and a version, so the import runs as the first user who may publish. The
 * permission is looked up carefully: the roles and permissions are seeded, and a fresh database (the installer,
 * a test) can be imported into before they exist.
 */
final class ImportAuthor
{
    public function first(): ?User
    {
        try {
            $publisher = User::query()->permission('website.publish')->first();
        } catch (PermissionDoesNotExist) {
            $publisher = null;
        }

        return $publisher ?? User::query()->first();
    }
}

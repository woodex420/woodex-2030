<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installs: additive only. Fresh installs receive the grant from the reference seeder.
        $role = Role::query()->where('name', 'accountant')->where('guard_name', 'web')->first();
        $permission = Permission::query()->where('name', 'accounts.view')->where('guard_name', 'web')->first();
        if ($role !== null && $permission !== null) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only: we cannot distinguish a pre-existing custom grant from this migration's grant.
        // A reviewed explicit revocation is required rather than removing legitimate access on rollback.
    }
};

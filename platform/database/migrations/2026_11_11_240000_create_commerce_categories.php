<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('commerce_categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        foreach (['commerce.catalog.view', 'commerce.catalog.manage'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (['owner', 'admin', 'sales_manager'] as $role) {
                Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
            if ($name === 'commerce.catalog.view') {
                Role::query()->where('name', 'sales')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_categories');
        // Permission grants are additive; never remove pre-existing custom grants on rollback.
    }
};

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
        Schema::create('category_content_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('commerce_categories')->restrictOnDelete();
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::table('commerce_categories', function (Blueprint $table): void {
            $table->unsignedInteger('content_version')->default(0);
            $table->foreignId('draft_revision_id')->nullable()->constrained('category_content_revisions')->restrictOnDelete();
            $table->foreignId('published_revision_id')->nullable()->constrained('category_content_revisions')->restrictOnDelete();
            $table->timestamp('content_published_at')->nullable();
        });
        Schema::create('category_content_previews', function (Blueprint $table): void {
            $table->foreignId('revision_id')->constrained('category_content_revisions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('previewed_at');
            $table->string('context_hash', 64);
            $table->primary(['revision_id', 'user_id']);
        });
        $permission = Permission::findOrCreate('commerce.catalog.publish', 'web');
        foreach (['owner', 'admin'] as $name) {
            Role::where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('category_content_previews');
        Schema::table('commerce_categories', function (Blueprint $table): void {
            $table->dropForeign(['draft_revision_id']);
            $table->dropForeign(['published_revision_id']);
            $table->dropColumn(['content_version', 'draft_revision_id', 'published_revision_id', 'content_published_at']);
        });
        Schema::dropIfExists('category_content_revisions');
        // Custom permission grants cannot safely be distinguished on rollback.
    }
};

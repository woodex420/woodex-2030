<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never discard or silently consolidate financial history to make this index fit.
        if (DB::table('invoices')->select('project_milestone_id')->whereNotNull('project_milestone_id')
            ->groupBy('project_milestone_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate milestone invoices need an Accounts review before this migration can run. No financial records have been changed.');
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique('project_milestone_id', 'invoices_project_milestone_unique');
        });
    }

    public function down(): void
    {
        // InnoDB may use the unique key as the FK's only supporting index. Preserve a
        // non-unique lookup before removing uniqueness; never disable or drop the FK.
        if (! Schema::hasIndex('invoices', 'invoices_project_milestone_lookup')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->index('project_milestone_id', 'invoices_project_milestone_lookup');
            });
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_project_milestone_unique');
        });
    }
};

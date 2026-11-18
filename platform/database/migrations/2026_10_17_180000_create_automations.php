<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.5: the owner's on/off switch per automation. Rows only exist once someone toggles —
 * the catalog lives in code (Automation::CATALOG) and a missing row means "enabled", so a
 * fresh install or an un-seeded update is correct without any seeding step.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable on MySQL (non-transactional DDL) — see the P8.4 production incident.
        if (Schema::hasTable('automations')) {
            return;
        }

        Schema::create('automations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->boolean('is_enabled')->default(true)->index();
            // Audit trail only, deliberately without a foreign key: the 17 Oct production
            // incidents settled the rule that the one-click update must survive anything a
            // shared host throws at it, and a constraint on a handful of audit rows is not
            // worth a failed deploy. The id is written by Automation::toggle() from the
            // authenticated user, never from request input.
            $table->foreignId('toggled_by')->nullable()->index();
            $table->timestamp('toggled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};

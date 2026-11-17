<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real connectors, not roadmap cards (owner decision, 2026-10-21).
 *
 * One row per provider that has actually been authorised. The credentials column is encrypted by the model, so
 * a token never appears in the database, a log or a page. The row is deleted on disconnect; a failed check
 * keeps it with status `error` so the hub can say what happened instead of pretending it is connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->string('status', 20)->default('connected')->index();   // connected | error
            $table->string('account', 191)->nullable();                     // github login, Google address, API host
            $table->text('credentials');                                    // encrypted JSON
            $table->json('scopes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};

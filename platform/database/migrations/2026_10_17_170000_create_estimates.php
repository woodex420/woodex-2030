<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.3: persistent, versioned estimates (docs/19 §7). An estimate is a staff document — the
 * inputs, the rate snapshot and the computed range live on the row, so later rate-book changes
 * never silently rewrite it; conversion to a quotation is recorded on both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable on MySQL (non-transactional DDL): each step is guarded, so a retry after an
        // interrupted first attempt records the migration instead of fatalling on what exists.
        // The CREATE TABLE and the ALTER are separate statements — an interruption can land
        // between them, so the estimates table and the quotes.estimate_id column are checked apart.
        if (! Schema::hasTable('estimates')) {
            Schema::create('estimates', function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 32)->unique();
                $table->string('number', 30)->unique();
                $table->string('service_key', 60);
                $table->string('title');
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('lead_id')->nullable();
                $table->json('inputs');
                $table->text('assumptions')->nullable();
                $table->text('exclusions')->nullable();
                $table->unsignedInteger('contingency_bp')->default(0);
                $table->json('catalog_lines')->nullable();
                $table->json('computed');
                $table->string('status', 20)->default('draft');
                $table->unsignedBigInteger('quote_id')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
                $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
                $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users');
                $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['status', 'service_key']);
            });
        }

        if (! Schema::hasColumn('quotes', 'estimate_id')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->unsignedBigInteger('estimate_id')->nullable()->after('template_id');
                $table->foreign('estimate_id')->references('id')->on('estimates')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign(['estimate_id']);
            $table->dropColumn('estimate_id');
        });

        Schema::dropIfExists('estimates');
    }
};

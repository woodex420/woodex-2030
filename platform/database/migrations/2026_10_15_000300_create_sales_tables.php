<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales: rate book, estimator rates, quote templates, quotes and their children (docs/04 §5).
 * Money columns are *_minor BIGINT (paisa); percentages *_bp (basis points); quantities DECIMAL(14,3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('category', 80)->index();
            $table->string('description', 500);
            $table->string('unit', 20);
            $table->bigInteger('rate_minor')->default(0);
            $table->bigInteger('internal_cost_minor')->nullable();
            $table->string('finish', 15)->nullable();
            $table->string('business_line', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('estimator_rates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 80);
            $table->string('hint', 200)->nullable();
            $table->string('unit', 20);
            $table->string('unit_label', 60);
            $table->bigInteger('essential_minor');
            $table->bigInteger('standard_minor');
            $table->bigInteger('premium_minor');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            $table->string('business_line', 20)->default('interior');
            $table->string('kind', 20)->default('execution');
            $table->string('milestone_plan', 30)->default('execution_50_40_10');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('scope_html')->nullable();
            $table->json('blueprint');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('number', 30)->index();
            $table->string('option_label', 40)->default('');
            $table->unsignedSmallInteger('revision')->default(0);
            $table->string('sequence_key', 50);
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('quote_templates')->nullOnDelete();
            $table->string('business_line', 20);
            $table->string('title', 200);
            $table->string('status', 25)->default('draft')->index();
            $table->char('currency', 3)->default('PKR');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->bigInteger('discount_value')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('other_charges_minor')->default(0);
            $table->string('other_charges_label', 120)->nullable();
            $table->unsignedInteger('tax_bp')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('rounding_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('internal_cost_minor')->default(0);
            $table->integer('margin_bp')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_name', 160)->nullable();
            $table->string('accepted_phone', 20)->nullable();
            $table->string('accepted_ip', 45)->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('scope_html')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('signatories')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['number', 'option_label']);
        });

        Schema::create('quote_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('unit', 20);
            $table->decimal('qty', 14, 3)->default(1);
            $table->bigInteger('rate_minor')->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->bigInteger('discount_value')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('line_total_minor')->default(0);
            $table->bigInteger('internal_cost_minor')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedSmallInteger('percent_bp');
            $table->bigInteger('amount_minor')->default(0);
            $table->string('trigger', 40)->default('on_acceptance');
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->json('snapshot');
            $table->bigInteger('total_minor');
            $table->string('pdf_path', 255)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['quote_id', 'revision']);
        });

        Schema::create('quote_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('channel', 20)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['quote_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_events');
        Schema::dropIfExists('quote_versions');
        Schema::dropIfExists('quote_milestones');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quote_sections');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_templates');
        Schema::dropIfExists('estimator_rates');
        Schema::dropIfExists('catalog_items');
    }
};

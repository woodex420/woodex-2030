<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM: companies, contacts, tags, pipeline stages, leads, activities, tasks, site visits (docs/04 §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->index();
            $table->string('type', 20)->default('company');
            $table->string('ntn', 30)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('website', 191)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('phone', 20)->nullable()->index();
            $table->string('alt_phone', 20)->nullable()->index();
            $table->string('whatsapp', 20)->nullable()->index();
            $table->string('email', 160)->nullable()->index();
            $table->string('city', 80)->nullable();
            $table->string('area', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('client_type', 20)->default('individual');
            $table->string('preferred_language', 5)->default('en');
            $table->boolean('is_client')->default(false)->index();
            $table->timestamp('became_client_at')->nullable();
            $table->string('source', 40)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('consent_marketing')->default(false);
            $table->boolean('consent_whatsapp')->default(false);
            $table->timestamp('opted_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->string('color', 20)->default('gray');
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');

            $table->primary(['tag_id', 'taggable_id', 'taggable_type']);
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('type', 10)->default('open');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('color', 20)->default('gray');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 20)->unique();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('business_line', 20)->index();
            $table->string('service', 60)->nullable();
            $table->string('property_type', 30)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('area', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedInteger('area_sqft')->nullable();
            $table->string('budget_band', 30)->nullable();
            $table->string('timeline', 20)->nullable();
            $table->string('lead_type', 20)->default('new');
            $table->foreignId('referrer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('referrer_name', 160)->nullable();
            $table->string('priority', 10)->default('normal');
            $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->timestamp('stage_changed_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->bigInteger('expected_value_minor')->nullable();
            $table->string('next_action_type', 20)->nullable();
            $table->timestamp('next_action_at')->nullable()->index();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('sla_due_at')->nullable()->index();
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 60)->nullable();
            $table->text('lost_note')->nullable();
            $table->string('source', 40)->index();
            $table->string('source_detail', 160)->nullable();
            $table->text('message')->nullable();
            $table->json('attribution')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage_id', 'owner_id']);
            $table->index('created_at');
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('type', 30);
            $table->string('direction', 3)->nullable();
            $table->string('outcome', 30)->nullable();
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['contact_id', 'occurred_at']);
            $table->index(['lead_id', 'occurred_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 20)->default('todo');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 15)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assignee_id', 'status', 'due_at']);
        });

        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at')->index();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('address', 255)->nullable();
            $table->string('map_url', 500)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('client_confirmed_at')->nullable();
            $table->json('reminders')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->json('measurements')->nullable();
            $table->string('next_step', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};

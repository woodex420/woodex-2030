<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOT-003: the assistant's knowledge base — the published pages' own words plus manual
 * Q&A — and BOT-008/009: one row per day per channel/provider of usage, which is both the
 * cost cap's counter and the analytics' source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->mediumText('content');
            $table->string('source', 16)->default('manual'); // manual | cms
            $table->unsignedBigInteger('page_id')->nullable(); // the CMS page this mirrors, when source = cms
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bot_usages', function (Blueprint $table): void {
            $table->id();
            $table->date('usage_date');
            $table->string('channel', 24);
            $table->string('provider', 24);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('replies')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('handoffs')->default(0);
            $table->unsignedInteger('guardrail_hits')->default(0);
            $table->timestamps();
            $table->unique(['usage_date', 'channel', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_usages');
        Schema::dropIfExists('bot_knowledge');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks (INT, plan 35): the owner's own endpoints — Zapier, Make, a CRM — that Woodex calls when
 * something happens. An endpoint carries its own HMAC signing secret (encrypted by the model, like connector
 * credentials); every delivery is a row, so "did the lead reach Zapier?" is answered from the database with
 * the attempt count and the endpoint's own answer, not from hope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('url', 500);
            $table->text('secret');                                     // encrypted signing secret
            $table->json('events');                                     // list<string> of WebhookEvents keys
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbound_webhook_id')->constrained('outbound_webhooks')->cascadeOnDelete();
            $table->string('event', 40)->index();                       // lead.created | quote.sent | invoice.paid | webhook.test
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();  // pending | sent | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['outbound_webhook_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('outbound_webhooks');
    }
};

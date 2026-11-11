<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S8 / TGM-008: messages that waited out a chat's quiet hours instead of being lost.
 * The Telegram channel queues here while a link is muted; woodex:telegram-flush delivers
 * once the mute ends. Delivered rows are pruned after a week by the same flush.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_link_id')->constrained('telegram_links')->cascadeOnDelete();
            $table->text('text');
            $table->json('buttons')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_pending_messages');
    }
};

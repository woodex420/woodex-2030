<?php

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversations phase (P4): one inbox for WhatsApp and the website chat widget (CNV-001, CNV-002, WAP-001…004).
 *
 * A conversation is one thread with one contact on one channel. A message is one turn in it, and carries its own
 * delivery status, so the inbox can show the ticks a client sees. `window_expires_at` is Meta's 24-hour rule made
 * visible: while it is in the future the team may write freely, outside it only approved templates go out
 * (CNV-005, WAP-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('channel', 20)->default(Channel::Whatsapp->value)->index();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 160)->nullable();
            $table->string('status', 20)->default(ConversationStatus::Open->value)->index();
            $table->string('external_number', 20)->nullable()->index(); // the WhatsApp number on the other side
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('window_expires_at')->nullable(); // Meta's 24-hour free-form window
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('last_direction', 3)->nullable();
            $table->string('last_preview', 240)->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('labels')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3)->default(MessageDirection::In->value);
            $table->string('type', 20)->default(MessageType::Text->value);
            $table->text('body')->nullable();
            $table->string('external_id', 191)->nullable()->unique(); // Meta's wamid; makes replays harmless
            $table->string('status', 20)->default(MessageStatus::Sent->value);
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who in the team wrote it
            $table->string('media_disk', 20)->nullable();
            $table->string('media_path', 255)->nullable();
            $table->string('media_mime', 80)->nullable();
            $table->string('media_name', 200)->nullable();
            $table->json('meta')->nullable(); // latitude/longitude, reaction emoji, button payload …
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};

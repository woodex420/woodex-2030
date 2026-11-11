<?php

use App\Domain\System\Enums\WhatsAppTemplateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio's WhatsApp templates, mirrored from Meta (WAP-005).
 *
 * `event_key` is the whole point of the table: it says which template carries which moment, so the app never has
 * a template name hard-coded and the studio can change the wording without a developer. One template per moment,
 * which is why it is unique — two templates both claiming "quotation" would only be a coin toss at send time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('meta_id', 64)->nullable()->index();
            $table->string('name', 120);
            $table->string('language', 12)->default('en');
            $table->string('category', 24)->nullable(); // UTILITY, MARKETING, AUTHENTICATION
            $table->string('status', 20)->default(WhatsAppTemplateStatus::Pending->value)->index();
            $table->json('components')->nullable(); // Meta's own shape, kept so we can send and re-read it
            $table->text('body')->nullable(); // the BODY text, extracted so the list can show it
            $table->string('event_key', 40)->nullable()->unique();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};

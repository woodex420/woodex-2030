<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * D-112: a client's "ok" on WhatsApp is a hint, not an acceptance.
     *
     * The system remembers that a reply sounded like acceptance, and which message said it, so the studio can check
     * and press Accept itself. The quotation's status is not touched — only a person accepts a price.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->timestamp('acceptance_hint_at')->nullable()->after('accepted_at');
            $table->foreignId('acceptance_hint_message_id')->nullable()->after('acceptance_hint_at')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acceptance_hint_message_id');
            $table->dropColumn('acceptance_hint_at');
        });
    }
};

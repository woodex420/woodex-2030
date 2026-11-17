<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for the client portal (PRJ-006): the client who has lost their link asks for a code on their
 * phone number or email, we send six digits, and the code dies as soon as it is used (or after 15 minutes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_access_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_access_codes');
    }
};

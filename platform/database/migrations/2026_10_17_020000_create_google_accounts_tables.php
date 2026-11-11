<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_subject', 255)->unique();
            $table->string('email');
            $table->timestamps();
        });
        Schema::create('google_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('google_subject');
            $table->string('email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->json('scopes');
            $table->string('status', 32)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('connected_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
        Schema::dropIfExists('google_identities');
    }
};

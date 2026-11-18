<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7 (SOC-005/006): the social planner's posts — one composition, many targets. The composer,
 * review flow and calendar are fully keyless; publishing itself waits for the studio's connected
 * accounts (Meta app review and LinkedIn access are the owner's to start — docs/16 §8), so a
 * scheduled post honestly waits instead of pretending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->text('text');
            $table->text('text_ur')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('first_comment', 500)->nullable();
            $table->string('hashtags', 500)->nullable();
            $table->json('media_ids');                  // from the shared website media library (SOC-010)
            $table->json('targets');                    // list of: facebook | instagram | linkedin
            $table->string('status', 20)->default('draft')->index();  // draft | in_review | approved | scheduled | published | failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};

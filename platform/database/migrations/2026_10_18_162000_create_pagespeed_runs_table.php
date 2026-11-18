<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-3 F9 (docs/14 §5a): the speed dashboard's history. One row per PageSpeed run — the studio
 * watches a page's score move over time instead of trusting a single number. Runs are keyed by URL
 * and strategy; created_at is when Google answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagespeed_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('url', 500);
            $table->string('strategy', 10);          // mobile | desktop
            $table->unsignedTinyInteger('score');    // 0–100
            $table->json('metrics');
            $table->timestamps();

            $table->index(['url', 'strategy', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagespeed_runs');
    }
};

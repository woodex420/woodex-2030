<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section settings (19 Oct, builder parity): the background, spacing, width and alignment an editor
 * chooses for one section, and the label it is known by in the editor. They live beside the section's
 * content, not inside it, because they describe the wrapper — see SectionStyle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_blocks', function (Blueprint $table): void {
            $table->json('settings')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('page_blocks', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};

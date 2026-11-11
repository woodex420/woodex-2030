<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_series', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 140)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $source = json_decode(file_get_contents(resource_path('data/commerce-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($source['series'] as $position => $series) {
            DB::table('commerce_series')->insert(['slug' => $series['id'], 'name' => $series['name'], 'position' => $position, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_series');
    }
};

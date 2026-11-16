<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('description', 200);
            $table->string('category', 20)->default('material')->index();
            $table->decimal('qty', 14, 3)->default(1);
            $table->string('unit', 20)->nullable();
            $table->bigInteger('amount_minor'); // the line's cost in paisa
            $table->date('incurred_on')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_expenses');
    }
};

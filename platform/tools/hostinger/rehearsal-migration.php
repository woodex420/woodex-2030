<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Not a migration of the application: a stand-in for "the next release brings a database change".
|
| tools/hostinger/redeploy.sh copies this file into the second release of a rehearsal, so that the owner's
| "Apply update" page has something to apply. The release builder leaves tools/ out, so it is never shipped.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rehearsal_second_release', function (Blueprint $table): void {
            $table->id();
            $table->string('note', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rehearsal_second_release');
    }
};

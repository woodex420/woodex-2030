<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The keys of the creates under /api/v1 (master list T-063; App\Support\Api\Idempotency).
 *
 * A client makes a key for each thing it creates and sends it with the request. The first request with a key
 * writes the record and this row in one transaction; a second request with the same key is answered from this row
 * and writes nothing. The unique key on (scope, key_hash) is what makes two requests in the same moment safe: the
 * second waits for the first, then finds its row. Neither the key nor the request is kept, only their hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            // Which create the key belongs to: "leads", "quote-requests".
            $table->string('scope', 40);
            $table->char('key_hash', 64);
            // What was sent with the key, as a hash: the same key with other content is refused.
            $table->char('request_hash', 64);
            // The HTTP status and what the first answer was made from. Empty only inside the first request's own
            // transaction: nobody else ever reads such a row.
            $table->unsignedSmallInteger('status')->nullable();
            $table->json('answer')->nullable();
            $table->timestamp('created_at');

            $table->unique(['scope', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};

<?php

declare(strict_types=1);

namespace App\Domain\Sales\Events;

use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteVersion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a quotation version is frozen and marked sent (any channel — the WhatsApp
 * sender works on a version SendQuote already froze, so this is the one dispatch point).
 */
final class QuoteSent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public QuoteVersion $version,
        public string $channel,
    ) {}
}

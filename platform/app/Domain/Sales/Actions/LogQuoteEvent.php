<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Models\User;

/**
 * Records a quote event (QUO-010) and puts it on the client's timeline (lead + contact), with the quote as subject.
 */
final class LogQuoteEvent
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{quote_version_id?: int, channel?: string, ip?: string|null, user_agent?: string|null}  $attributes
     */
    public function handle(Quote $quote, string $type, ?User $by, string $timelineText, array $data = [], array $attributes = []): QuoteEvent
    {
        $now = now();

        $event = QuoteEvent::create([
            'quote_id' => $quote->id,
            'type' => $type,
            'actor_user_id' => $by?->id,
            'data' => $data === [] ? null : $data,
            'occurred_at' => $now,
            ...$attributes,
        ]);

        Activity::create([
            'contact_id' => $quote->contact_id,
            'lead_id' => $quote->lead_id,
            'user_id' => $by?->id,
            'subject_type' => $quote->getMorphClass(),
            'subject_id' => $quote->id,
            'type' => ActivityType::QuoteEvent,
            'body' => $timelineText,
            'data' => ['quote_event' => $type, 'quote' => $quote->label()] + $data,
            'occurred_at' => $now,
        ]);

        if ($quote->lead_id !== null) {
            Lead::query()->whereKey($quote->lead_id)->update(['last_activity_at' => $now]);
        }

        Contact::query()->whereKey($quote->contact_id)->update(['last_activity_at' => $now]);

        return $event;
    }
}

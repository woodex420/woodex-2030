<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Jobs\ProcessWhatsAppEvent;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

/** The persisted inbox survives a failed enqueue; cron re-enqueues an expired five-minute queue lease. */
final class QueueWhatsAppEvent
{
    public function handle(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($eventId);
            if ($event->provider !== 'whatsapp' || ! $event->signature_valid
                || in_array($event->status, [WebhookStatus::Processed, WebhookStatus::Ignored], true)
                || $event->attempts >= ProcessWhatsAppEvent::MAX_ATTEMPTS
                || $event->next_attempt_at?->isFuture()
                || $event->queued_at?->gt(now()->subMinutes(5))) {
                return false;
            }

            $event->forceFill(['queued_at' => now()])->save();
            ProcessWhatsAppEvent::dispatch($event->id)->afterCommit();

            return true;
        });
    }
}

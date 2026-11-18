<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Jobs;

use App\Domain\Conversations\Actions\ReceiveWhatsAppMessage;
use App\Domain\Conversations\Actions\RecordMessageStatus;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Sales\Actions\SuggestQuoteAcceptance;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** One durable, signature-verified inbox event. Duplicate jobs serialize on its row, not a stale model. */
final class ProcessWhatsAppEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int MAX_ATTEMPTS = 5;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public readonly int $eventId) {}

    public function handle(ReceiveWhatsAppMessage $receive, RecordMessageStatus $statuses): void
    {
        $storedMessage = null;

        try {
            DB::transaction(function () use (&$storedMessage, $receive, $statuses): void {
                $event = WebhookEvent::query()->lockForUpdate()->findOrFail($this->eventId);
                if ($event->provider !== 'whatsapp' || ! $event->signature_valid
                    || in_array($event->status, [WebhookStatus::Processed, WebhookStatus::Ignored], true)
                    || $event->attempts >= self::MAX_ATTEMPTS || $event->next_attempt_at?->isFuture()) {
                    return;
                }

                $payload = $event->payload;
                $sourceNumber = $payload['metadata']['phone_number_id'] ?? null;
                $ignored = $sourceNumber !== null && (! is_string($sourceNumber) || $sourceNumber !== (string) config('services.whatsapp.phone_number_id'));

                if (! $ignored && $event->type === 'message') {
                    // The exact id also selects the right message when replaying legacy, unnormalized batches.
                    $storedMessage = $receive->handle($payload, $event->external_id);
                    if ($storedMessage === null) {
                        throw new RuntimeException('Invalid inbound event.');
                    }
                } elseif (! $ignored && $event->type === 'status') {
                    $status = collect((array) ($payload['statuses'] ?? []))->first(fn (mixed $item): bool => is_array($item) && ($item['id'] ?? '').':'.($item['status'] ?? '') === $event->external_id);
                    if (! is_array($status)) {
                        throw new RuntimeException('Invalid status event.');
                    }
                    if (MessageStatus::tryFrom((string) $status['status']) === null) {
                        $ignored = true;
                    } elseif ($statuses->handle((string) $status['id'], (string) $status['status'], isset($status['errors']) ? (array) $status['errors'] : null) === null) {
                        // Meta can beat the outbound write: retain this event until that message exists.
                        throw new RuntimeException('Outbound message not available yet.');
                    }
                } elseif (! $ignored) {
                    $ignored = true;
                }

                $event->forceFill([
                    'status' => $ignored ? WebhookStatus::Ignored : WebhookStatus::Processed,
                    'attempts' => $event->attempts + 1,
                    'processed_at' => now(), 'next_attempt_at' => null, 'queued_at' => null, 'error' => null,
                ])->save();
            });

            // A client's "ok" on WhatsApp is a hint, never an acceptance (D-112): it is remembered for the
            // team to confirm. After the committed transaction, and best-effort, so a notification can never
            // roll a stored message back nor block the processed marker.
            if ($storedMessage !== null) {
                try {
                    app(SuggestQuoteAcceptance::class)->handle($storedMessage);
                } catch (Throwable $suggestionFailed) {
                    Log::warning('Quote acceptance hint failed.', ['event_id' => $this->eventId, 'exception' => $suggestionFailed::class]);
                }
            }
        } catch (Throwable $exception) {
            // The domain write and processed marker rolled back together. Never retain provider bodies,
            // SQL bindings, tokens or exception messages in the replay ledger or application log.
            DB::transaction(function (): void {
                $event = WebhookEvent::query()->lockForUpdate()->findOrFail($this->eventId);
                if (in_array($event->status, [WebhookStatus::Processed, WebhookStatus::Ignored], true)) {
                    return;
                }
                $attempts = $event->attempts + 1;
                $event->forceFill([
                    'status' => WebhookStatus::Failed, 'attempts' => $attempts, 'processed_at' => null,
                    'queued_at' => null,
                    'next_attempt_at' => $attempts < self::MAX_ATTEMPTS ? now()->addSeconds(60 * (2 ** ($attempts - 1))) : null,
                    'error' => $attempts < self::MAX_ATTEMPTS ? 'Processing failed; retry scheduled.' : 'Retry limit reached; staff review required.',
                ])->save();
            });
            Log::warning('WhatsApp inbox event failed.', ['event_id' => $this->eventId, 'exception' => $exception::class]);
        }
    }
}

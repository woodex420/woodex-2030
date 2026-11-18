<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversations\Actions\QueueWhatsAppEvent;
use App\Domain\Conversations\Jobs\ProcessWhatsAppEvent;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Shared-hosting recovery: bounded polling, no Redis or daemon. Only trusted stored WhatsApp events qualify. */
final class RetryWhatsAppWebhooks extends Command
{
    protected $signature = 'woodex:retry-whatsapp-webhooks {--event= : Redrive one failed event after staff review} {--limit=100 : Maximum due events to enqueue}';

    protected $description = 'Enqueue due WhatsApp inbox retries, or explicitly redrive one reviewed failure';

    public function handle(QueueWhatsAppEvent $queue): int
    {
        if ($this->option('event') !== null) {
            $id = filter_var($this->option('event'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $this->error('Supply a positive event ID.');

                return self::FAILURE;
            }
            $reset = DB::transaction(function () use ($id): bool {
                $event = WebhookEvent::query()->lockForUpdate()->find($id);
                if ($event === null || $event->provider !== 'whatsapp' || ! $event->signature_valid || $event->status !== WebhookStatus::Failed) {
                    return false;
                }
                $event->forceFill(['attempts' => 0, 'next_attempt_at' => null, 'queued_at' => null, 'error' => null])->save();

                return true;
            });
            if (! $reset) {
                $this->error('Only a signature-verified, failed WhatsApp event may be redriven.');

                return self::FAILURE;
            }
            Log::notice('WhatsApp event manually redriven from console.', ['event_id' => $id]);
            $queue->handle($id);
            $this->info('Event queued for another bounded retry cycle.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        $ids = WebhookEvent::query()->where('provider', 'whatsapp')->where('signature_valid', true)
            ->whereIn('status', [WebhookStatus::Received->value, WebhookStatus::Failed->value])
            ->where('attempts', '<', ProcessWhatsAppEvent::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += (int) $queue->handle((int) $id);
        }
        $this->info("Queued {$count} WhatsApp event(s).");

        return self::SUCCESS;
    }
}

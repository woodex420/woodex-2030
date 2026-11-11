<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Support\PublicUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One POST to one endpoint, signed so the receiver can prove it came from Woodex
 * (X-Woodex-Signature: sha256 HMAC of the exact body). The outcome lands on the delivery row
 * and in the integration log, so the hub's history stays the one record.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('webhook')->find($this->deliveryId);

        if ($delivery === null || $delivery->webhook === null || $delivery->status === 'sent') {
            return;
        }

        if (! $delivery->webhook->is_active) {
            $delivery->forceFill(['status' => 'failed', 'error' => 'Endpoint paused before delivery.'])->save();

            return;
        }

        $started = microtime(true);
        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $delivery->forceFill(['attempts' => max($this->attempts(), $delivery->attempts + 1)])->save();

        try {
            $response = Http::withHeaders([
                'X-Woodex-Event' => $delivery->event,
                'X-Woodex-Delivery' => (string) $delivery->id,
                'X-Woodex-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $delivery->webhook->secret),
                'User-Agent' => 'Woodex-OS-Webhooks',
            ])->withOptions(PublicUrlGuard::requestOptions($delivery->webhook->url))->timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($delivery->webhook->url);
        } catch (Throwable $exception) {
            $delivery->forceFill(['error' => 'Destination could not be reached or was refused by the public-address guard.'])->save();

            $this->log($delivery, false, (int) round((microtime(true) - $started) * 1000));
            throw new RuntimeException('Webhook destination could not be reached.');
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => 'sent',
                'response_status' => $response->status(),
                'error' => null,
                'delivered_at' => now(),
            ])->save();
            $this->log($delivery, true, (int) round((microtime(true) - $started) * 1000));

            return;
        }

        $delivery->forceFill([
            'response_status' => $response->status(),
            'error' => 'Endpoint answered '.$response->status().'.',
        ])->save();

        $this->log($delivery, false, (int) round((microtime(true) - $started) * 1000));

        throw new RuntimeException('Webhook endpoint answered '.$response->status().'.');
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->with('webhook')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'sent') {
            return;
        }

        $delivery->forceFill(['status' => 'failed', 'attempts' => max((int) $delivery->attempts, $this->attempts())])->save();
        $this->log($delivery, false, null);
    }

    private function log(WebhookDelivery $delivery, bool $ok, ?int $durationMs): void
    {
        IntegrationLog::query()->create([
            'integration' => 'webhooks', 'direction' => 'out', 'action' => 'deliver',
            'ok' => $ok, 'duration_ms' => $durationMs,
            'request' => ['event' => $delivery->event, 'endpoint' => $delivery->webhook?->name],
            'error' => $ok ? null : $delivery->error, 'created_at' => now(),
        ]);
    }
}
